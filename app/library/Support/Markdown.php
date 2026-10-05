<?php
declare(strict_types=1);

namespace app\library\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Render Markdown → HTML yang aman (panduan template, SPECS.md §7.2b).
 *
 * Dipakai untuk menampilkan `templates/<slug>/guide.md` di UI. Markdown adalah
 * konten yang ikut repo (bukan input user), tetapi tetap dirender dengan
 * kebijakan aman berlapis supaya galeri tidak bisa menjadi jalur XSS bila isi
 * template kelak berasal dari sumber yang kurang tepercaya:
 *  - `html_input => 'escape'`  — HTML mentah di Markdown di-escape, bukan dirender,
 *  - `allow_unsafe_links => false` — tautan `javascript:`/`data:` dinonaktifkan,
 *  - `ExternalLinkExtension` — tautan eksternal `target="_blank"` +
 *    `rel="noopener noreferrer"` (anti tabnabbing).
 *
 * Statik murni & tanpa I/O: aman dipanggil dari worker Webman persistent.
 * Converter dibangun sekali (lazy static) agar tidak dibuat ulang tiap render.
 */
final class Markdown
{
    /** Opsi `noopener`/`noreferrer` ExternalLinkExtension untuk tautan eksternal. */
    private const REL_TARGET = 'external';

    private static ?MarkdownConverter $converter = null;

    private function __construct()
    {
    }

    /**
     * Konversi Markdown menjadi HTML aman.
     *
     * Input kosong/whitespace → `''`. Kegagalan apa pun (konfigurasi, parser)
     * ditelan dan dikembalikan sebagai `''` — panduan tidak boleh menggagalkan
     * halaman yang merendernya.
     */
    public static function toHtml(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        try {
            return (string) self::converter()->convert($markdown);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter === null) {
            $environment = new Environment([
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
                'external_link' => [
                    // Tanpa host internal: semua tautan ber-host dianggap eksternal.
                    'internal_hosts' => '',
                    'open_in_new_window' => true,
                    'noopener' => self::REL_TARGET,
                    'noreferrer' => self::REL_TARGET,
                ],
            ]);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new ExternalLinkExtension());
            // Tabel pipe GFM (`| a | b |`) tidak aktif di CommonMark inti.
            $environment->addExtension(new TableExtension());

            self::$converter = new MarkdownConverter($environment);
        }

        return self::$converter;
    }
}
