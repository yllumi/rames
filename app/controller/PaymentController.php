<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Billing\DuitkuClient;
use app\library\Billing\TopUpService;
use support\Request;
use Throwable;
use Webman\Http\Response;

/**
 * Callback pembayaran Duitku (SPECS.md §7.12, plan §5.7).
 *
 * Endpoint publik (tanpa sesi — Duitku tidak punya cookie kita) yang
 * **hanya** menerima POST callback `x-www-form-urlencoded`. Kebenaran pembayaran
 * TIDAK pernah disimpulkan dari redirect browser/`returnUrl`; satu-satunya jalur
 * penambahan saldo adalah callback tervalidasi di sini.
 *
 * Controller ini mediator murni: seluruh verifikasi (merchantCode, signature
 * HMAC `hash_equals`, kecocokan amount ↔ order tersimpan) dan idempotensi
 * (`pending → paid` + penulisan saldo dalam satu transaksi) sudah ditegakkan
 * `TopUpService::handleCallback()`. Controller hanya memetakan hasil ke status
 * HTTP, menulis audit ringkas, dan **tidak pernah** membocorkan data sensitif.
 *
 * Alur respons (plan §5.7):
 *  - fitur mati / kredensial tidak lengkap → **404** (endpoint disembunyikan);
 *  - payload cacat (tanpa `merchantOrderId`/`resultCode`) → **400**;
 *  - verifikasi gagal (signature/amount/merchant) → **403**;
 *  - diproses (status apa pun) → **200 `OK`** supaya Duitku tidak retry.
 *
 * Tanpa state properti: dependensi yang di-inject bersifat stateless (hanya
 * seam pengujian), dan service bawaan dibangun **di dalam** `callback()` — tidak
 * ada `session()`/`request()` di konstruktor.
 */
class PaymentController
{
    /** Service yang di-inject (seam tes); `null` = bangun bawaan saat request. */
    private ?TopUpService $service;

    public function __construct(?TopUpService $service = null)
    {
        $this->service = $service;
    }

    /**
     * `POST /payments/duitku/callback` — selalu balas cepat & tanpa detail.
     */
    public function callback(Request $request): Response
    {
        // 1) Sembunyikan endpoint bila fitur/kredensial tidak lengkap (fail-safe).
        if (!(bool) config('deploy.billing_topup_enabled', false)) {
            return $this->plain('Not Found', 404);
        }

        $client = new DuitkuClient();
        if (!$client->isConfigured()) {
            return $this->plain('Not Found', 404);
        }

        // 2) Payload wajib form-urlencoded; semua nilai harus skalar. Field array
        //    (`merchantOrderId[]=…`) ditolak sebagai 400 — bukan error 500 — karena
        //    endpoint ini publik dan dapat dipanggil siapa saja.
        $payload = (array) $request->post();
        foreach ($payload as $value) {
            if ($value !== null && !is_scalar($value)) {
                return $this->plain('Bad Request', 400);
            }
        }

        $orderId = trim((string) ($payload['merchantOrderId'] ?? ''));
        $resultCode = trim((string) ($payload['resultCode'] ?? ''));
        if ($orderId === '' || $resultCode === '') {
            return $this->plain('Bad Request', 400);
        }

        $service = $this->service ?? new TopUpService(null, null, $client);

        try {
            $result = $service->handleCallback($payload);
        } catch (Throwable) {
            // Tidak pernah membocorkan pesan internal; 500 memicu retry wajar
            // (order tetap pending sehingga retry aman/idempoten).
            $this->audit($orderId, 'error', 'exception');

            return $this->plain('Internal Server Error', 500);
        }

        $ok = ($result['ok'] ?? false) === true;
        if (!$ok) {
            $reason = (string) ($result['reason'] ?? 'invalid');
            $this->audit($orderId, 'rejected', $reason);

            // Payload cacat → 400; verifikasi gagal → 403 (tanpa detail).
            $status = in_array($reason, ['missing_order_id', 'unknown_result_code'], true) ? 400 : 403;

            return $this->plain($status === 400 ? 'Bad Request' : 'Forbidden', $status);
        }

        $this->audit((string) ($result['order_id'] ?? $orderId), (string) ($result['status'] ?? 'ok'), '');

        return $this->plain('OK', 200);
    }

    /**
     * Respons polos tanpa detail internal (Duitku hanya butuh status).
     */
    private function plain(string $body, int $status): Response
    {
        return response($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Audit ringkas ke `runtime/logs/billing/{Y-m-d}.log` (atau `billing_log_path`).
     *
     * Hanya order id + status + alasan — **tidak pernah** API key, signature
     * mentah, email, atau seluruh payload. Kegagalan menulis log diabaikan.
     */
    private function audit(string $orderId, string $status, string $reason): void
    {
        $configured = trim((string) config('deploy.billing_log_path', ''));
        $dir = $configured !== '' ? rtrim($configured, '/') : runtime_path('logs/billing');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $line = 'duitku callback order=' . ($orderId !== '' ? $orderId : '-') . ' status=' . $status;
        if ($reason !== '') {
            $line .= ' reason=' . $reason;
        }

        @file_put_contents(
            $dir . '/' . date('Y-m-d') . '.log',
            '[' . date('c') . '] ' . $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
