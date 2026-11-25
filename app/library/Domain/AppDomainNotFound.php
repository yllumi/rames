<?php
declare(strict_types=1);

namespace app\library\Domain;

use RuntimeException;

/**
 * Domain tambahan yang diminta tidak terdaftar pada app ini.
 *
 * Dipakai {@see AppDomainService::updateAliasTarget()} supaya pemanggil bisa
 * membedakan **tidak ditemukan** (API ⇒ 404, tanpa oracle app lain) dari
 * **validasi gagal** (API ⇒ 422). Pesannya tetap sama dengan perilaku lama
 * dashboard.
 */
final class AppDomainNotFound extends RuntimeException
{
}
