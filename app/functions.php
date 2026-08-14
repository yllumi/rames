<?php
declare(strict_types=1);

/**
 * Helper global dashboard deployer.
 */

use support\Request;

if (!function_exists('e')) {
    /**
     * HTML escape helper.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('current_user')) {
    /**
     * User yang sedang login dari session, atau null.
     */
    function current_user(): ?array
    {
        return session()->get('user');
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Token CSRF yang disimpan di session (dibuat jika belum ada).
     */
    function csrf_token(): string
    {
        $s = session();
        $token = $s->get('csrf_token');
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $s->set('csrf_token', $token);
        }
        return $token;
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Hidden input CSRF untuk form.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('flash_set')) {
    /**
     * Set pesan flash sekali tampil (type: success|error|info).
     */
    function flash_set(string $type, string $message): void
    {
        session()->set('flash', ['type' => $type, 'message' => $message]);
    }
}

if (!function_exists('flash_pull')) {
    /**
     * Ambil & hapus pesan flash.
     */
    function flash_pull(): ?array
    {
        return session()->pull('flash');
    }
}

if (!function_exists('site_subdomain')) {
    /**
     * Subdomain site = {name}.{APP_DOMAIN}.
     */
    function site_subdomain(string $name): string
    {
        return $name . '.' . config('deploy.app_domain', 'example.com');
    }
}
