<?php
declare(strict_types=1);

/**
 * Konfigurasi fitur "Rekam Error" (error klien + exception server 5xx).
 *
 * Semua nilai numerik dibaca **eksplisit** dari environment: `?:` menelan nilai
 * `0` yang sah (mis. `ERROR_LOG_RETENTION_DAYS=0` untuk membersihkan seluruh
 * data), jadi pola itu tidak dipakai di sini.
 */

$errorsInt = static function (string $name, int $default): int {
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    $value = trim($value);
    if ($value === '' || preg_match('/\A-?\d{1,9}\z/', $value) !== 1) {
        return $default;
    }

    return (int) $value;
};

return [
    // Retensi data error (hari). Grup & event yang lebih tua dibuang prune
    // oportunistik (maksimal sekali per jam lintas worker).
    'retention_days' => $errorsInt('ERROR_LOG_RETENTION_DAYS', 30),

    // Plafon total event tersimpan (semua grup). Event tertua dibuang lebih dulu.
    'max_events' => $errorsInt('ERROR_LOG_MAX_EVENTS', 5000),

    // Plafon sampel event per grup.
    'max_samples_per_group' => $errorsInt('ERROR_LOG_MAX_SAMPLES', 20),

    // Batas ukuran body laporan klien (byte).
    'max_payload_bytes' => $errorsInt('ERROR_REPORT_MAX_BYTES', 16384),

    // Kuota laporan per menit per user (klien) / per fingerprint (server).
    'rate_per_minute' => $errorsInt('ERROR_REPORT_RATE_PER_MIN', 20),

    // Batas panjang pesan & stack yang disimpan.
    'max_message_chars' => 2000,
    'max_stack_bytes' => 8192,

    // Jumlah baris per halaman daftar `/errors`.
    'per_page' => 50,
];
