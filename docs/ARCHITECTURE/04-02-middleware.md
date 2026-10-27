# 4.2 Middleware — `app/middleware/`

> Bagian dari [dokumentasi arsitektur](../ARCHITECTURE.md) · Spesifikasi: [SPECS.md](../SPECS.md) · Panduan operasional: [DETAILS.md](../../DETAILS.md)

| Middleware | Peran |
|---|---|
| `CsrfMiddleware` | Validasi token CSRF untuk semua POST/PUT/PATCH/DELETE. **Dua pengecualian sempit (path-presisi, larangan repo #17):** (1) proxy Adminer `#^/database/[^/]+/adminer(/|$)#`; (2) **callback Duitku** `#^/payments/duitku/callback$#` — **eksak & hanya POST** (pengecualian Adminer tidak dilebarkan; route lain termasuk `/credits/deposit` tetap wajib token). Callback diverifikasi di `PaymentController` (signature HMAC + amount + idempotensi, tanpa session) |
| `AuthMiddleware` | Lindungi semua route kecuali `/login` & aset statis; JSON 401 untuk request `/api/*` yang tidak login; **menyinkronkan user session dengan store `auth`** pada setiap request (user dihapus → sesi dibuang; role berubah → langsung berlaku). `PUBLIC_PATHS` memuat **`/payments/duitku/callback`** (path eksak, tanpa sesi — Duitku pengirim mesin tanpa cookie kita; keamanannya dari verifikasi di controller, bukan autentikasi) |
| `StaticFile` | Tolak akses path berisi `/.` (bawaan webman) |
| `AgentAuthMiddleware` | Autentikasi mesin-ke-mesin **mode `agent` saja** (plan §4.1): bearer token `hash_equals()` → `X-Rames-Timestamp` (toleransi ±60 s) → `X-Rames-Nonce` (sekali pakai, cache lintas-worker `NonceStore` di `runtime/agent-nonces.json`, TTL 120 s) → `X-Rames-Signature` **opsional** (bila header ada, wajib valid: HMAC-SHA256 `"{timestamp}\n{METHOD}\n{path}\n{sha256(body)}"` dengan key = token). Gagal apa pun ⇒ **401 tanpa detail** (tak ada oracle header mana yang salah). Di mode `dashboard` middleware ini **no-op mutlak**. Middleware bersifat **global**, jadi mode digerbangi di `config/middleware.php` — bukan hanya `config/route.php` |

Terdaftar global di `config/middleware.php` dengan urutan: CSRF → Auth → StaticFile. **(mode `agent`)** daftar global diganti `AgentAuthMiddleware` saja — route agent tidak melewati sesi/CSRF dashboard (kalau tidak, `/healthz` agent akan gagal).
