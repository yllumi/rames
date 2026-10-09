<?php
declare(strict_types=1);

namespace app\library\Billing;

use Webman\Exception\BusinessException;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * Dilempar oleh `BillingGate` saat saldo kredit member kurang dari deposit
 * minimum — blokir **fungsional**, bukan soal otorisasi (otorisasi tetap
 * satu-satunya milik `AppAccess`).
 *
 * extends BusinessException: kondisi ini bukan error server sehingga tidak
 * dicatat sebagai error oleh handler webman (pola `AppAccessDenied`).
 */
class InsufficientCredits extends BusinessException
{
    public function __construct(
        public readonly float $balance,
        public readonly float $required,
        string $message = '',
    ) {
        $text = trim($message);
        if ($text === '') {
            $text = sprintf(
                'Saldo kredit Anda %s, sedangkan minimum yang dibutuhkan %s. '
                . 'Tambahkan kredit di halaman Kredit.',
                Pricing::format($balance),
                Pricing::format($required)
            );
        }

        parent::__construct($text, 402);
    }

    /**
     * JSON `{code:402,msg}` status 402 untuk request /api/* & AJAX; request
     * halaman mendapat flash pesan lalu diarahkan ke halaman Kredit.
     */
    public function render(Request $request): ?Response
    {
        if ($request->expectsJson() || str_starts_with($request->path(), '/api/')) {
            return json(['code' => 402, 'msg' => $this->getMessage()])->withStatus(402);
        }

        flash_set('error', $this->getMessage());

        return redirect('/credits');
    }
}
