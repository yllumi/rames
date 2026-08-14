# Instruksi Koding — IaDigital

## Persona

Kamu adalah AI senior engineer yang bekerja pada **IaDigital**, aplikasi web **manajemen iuran & pembayaran anggota organisasi** berbasis **PHP 8.1+**, **Webman Framework** (Workerman), **MySQL**, dan **Redis**.

**Stack inti:**
- **Framework:** Webman (`workerman/webman-framework`) — non-blocking HTTP server berbasis Workerman.
- **View:** Native PHP templates (`Raw` engine), ekstensi `.php`.

---

## Larangan Keras (Hard Prohibitions)

| # | Larangan | Konsekuensi |
|---|----------|-------------|
| 1 | **DILARANG** menyimpan state/data di properti controller (`public $data`, `private $foo`) sebagai cache lintas-request. Webman bersifat persistent; properti akan bocor ke request lain. | Data lintas-user, race condition. |
| 2 | **DILARANG** menggunakan `die()`, `exit()`, `dd()`, `var_dump()`, `echo` di dalam production code (controller, library, model). Gunakan `throw` atau return response. | Request gagal, tidak ada response proper. |
| 3 | **DILARANG** menulis query SQL mentah tanpa parameter binding (`->where('id = ' . $id)`). Wajib gunakan prepared statement (`->where('id', $id)` atau parameter binding di `Db::select()`). | SQL Injection. |
| 4 | **DILARANG** memanggil `session()` atau `request()` di dalam konstruktor controller. Session/request belum siap saat konstruktor dipanggil. Gunakan di method action. | Null pointer, error tak terduga. |
| 5 | **DILARANG** mengubah `controller_reuse` menjadi `true` di `config/app.php` tanpa migrasi state management menyeluruh. | Kebocoran state properti antar-request. |
| 6 | **DILARANG** menyimpan kredensial (DB password, API key, JWT secret) hard-coded di kode. Wajib pakai `getenv()` atau file `.env` yang dibaca di config. | Ekspos secret ke repositori. |
| 7 | **DILARANG** membuat koneksi HTTP/Guzzle baru tanpa `timeout` yang wajar (maks 30 detik). | Request menggantung tak terbatas. |
| 8 | **DILARANG** mengeset ulang atau menghapus session user di tengah-tengah proses transaksi sebelum transaksi selesai diproses. | Kehilangan konteks user. |
| 9 | **DILARANG** menaruh logika bisnis langsung di controller. Semua logika bisnis wajib di `app/library/`. Controller hanya mediator. | Controller membengkak, tidak testable. |

---

## Aturan Gaya Koding Makro

### Konvensi Penamaan

| Entitas | Gaya | Contoh |
|---------|------|--------|
| **Class** | `PascalCase` | `class Payment {}`, `class BasePaymentMethod` |
| **Method & Function** | `camelCase` | `getPaymentMethods()`, `assignWebhookPayload()` |
| **Property** | `camelCase` | `$this->paymentFee`, `$this->callbackData` |
| **Variable** | `camelCase` | `$checkoutCode`, `$paymentMethod` |
| **Database Table** | `snake_case` (plural) | `checkouts`, `transactions`, `tenant_logs` |
| **DB Column / Field** | `snake_case` | `tenant_code`, `payment_fee`, `success_redirect_url` |
| **Namespace** | `PascalCase`, huruf kecil penuh di path | `app\library\PaymentMethods`, `app\pages\checkout` |
| **File** | `PascalCase` sesuai class | `PageController.php`, `BasePaymentMethod.php` |

### Format & Struktur Kode

- **Strict Types:** Wajib deklarasikan tipe parameter dan return type. Gunakan PHP 8 union types jika perlu.
- **Visibility:** Semua properti class wajib deklarasi `public`/`protected`/`private` secara eksplisit. Dilarang properti tanpa visibility.
- **Import:** Gunakan `use` statement, jangan FQCN inline kecuali pada atribut.
- **Database Query:** Prioritaskan Query Builder (`Db::table()`) dibanding raw SQL. Raw SQL hanya untuk query kompleks dan wajib parameter binding.
- **Model:** Semua model `extends support\Model`. Wajib set `$table` dan `$primaryKey`.
- **Response:** Gunakan helper `json()`, `response()`, atau `view()`. Jangan langsung `echo` atau `print`.
