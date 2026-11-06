# Rames MCP server

Server [MCP](https://modelcontextprotocol.io) kecil (stdio) yang membungkus **dua** endpoint
API eksternal Rames supaya bisa dipanggil asisten AI sebagai *tool*:

| Tool | Endpoint Rames | Sifat |
|---|---|---|
| `rames_app_status` | `GET /api/v1/apps/{slug}` | read-only (polling build) |
| `rames_build_app` | `POST /api/v1/apps/{slug}/build` | memicu build ulang (asinkron, `202`) |

Keduanya memakai **API key per user** (dashboard → Profil → API key) — tanpa sesi,
cookie, atau CSRF. Tool bertindak sebagai **pemilik key**: hak akses app (`AppAccess`)
dan gerbang kredit berlaku persis seperti aksi user itu di UI.

## Kebutuhan

- Python ≥ 3.9 — **hanya pustaka standar**, tanpa `pip install`, tanpa Node/npm.
- API key Rames (`rmk_...`). Plaintext hanya tampil sekali saat dibuat; bila hilang,
  generate ulang di Profil (key lama langsung mati).

## Menjalankan

Server dijalankan oleh klien MCP sebagai subprocess; tidak perlu dijalankan manual.

```bash
# uji mandiri (stub HTTP lokal, tanpa menyentuh dashboard nyata)
python3 tools/mcp/test_rames_mcp.py
```

## Konfigurasi

| Env | Wajib | Keterangan |
|---|---|---|
| `RAMES_URL` | tidak | Alamat dashboard, default `http://localhost:8000`. **Tanpa** garis miring di akhir — `//api/...` membuat server membalas 404 HTML. |
| `RAMES_API_KEY` | **ya** | API key per user (`rmk_...`). Tanpa ini tool menolak jalan (tanpa menyentuh jaringan). |
| `RAMES_TIMEOUT` | tidak | Timeout HTTP detik; default 30, di-cap 30. |

### VS Code / Copilot — `.vscode/mcp.json`

```json
{
  "servers": {
    "rames": {
      "type": "stdio",
      "command": "python3",
      "args": ["/ABSOLUTE/PATH/rames/tools/mcp/rames_mcp.py"],
      "env": {
        "RAMES_URL": "http://localhost:8123",
        "RAMES_API_KEY": "${input:rames-api-key}"
      }
    }
  },
  "inputs": [
    {
      "type": "promptString",
      "id": "rames-api-key",
      "description": "API key Rames (Profil → API key)",
      "password": true
    }
  ]
}
```

`${input:...}` membuat key tidak tersimpan di berkas konfigurasi (diminta sekali saat
server start). Bila klien Anda tidak mendukung `inputs`, isi `env.RAMES_API_KEY`
langsung — tetapi **jangan** commit berkas itu.

### Claude Desktop — `claude_desktop_config.json`

```json
{
  "mcpServers": {
    "rames": {
      "command": "python3",
      "args": ["/ABSOLUTE/PATH/rames/tools/mcp/rames_mcp.py"],
      "env": {
        "RAMES_URL": "http://localhost:8123",
        "RAMES_API_KEY": "rmk_..."
      }
    }
  }
}
```

Salinan siap-tempel juga tersedia di halaman dashboard **`/api-docs` → bagian MCP**.

## Perilaku respons

- Balasan API diteruskan apa adanya sebagai `structuredContent` (JSON) + teks rapi.
- HTTP ≥ 400 ⇒ `isError: true` dengan petunjuk singkat:
  `401` key tidak sah/dicabut · `402` kredit kurang · `404` app tidak ada/tidak berhak ·
  `409` app sedang deploying atau slug ambigu · `422` build ditolak (app impor/direktori hilang).
- `404` non-JSON diberi petunjuk khusus: hampir selalu masalah **path** (`RAMES_URL`
  berakhiran `/` atau path berakhiran `/`), bukan API key.
- Slug divalidasi sebelum request (`^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`, ≤63) sehingga
  tidak bisa dipakai keluar dari path.

## Keamanan

- API key dibaca dari environment dan **tidak pernah** ditulis ke stdout/stderr.
- Redirect HTTP **tidak diikuti** (header `Authorization` tidak boleh pindah host).
- Timeout ≤ 30 detik; satu request per tool call (tanpa retry otomatis).
- Key yang bocor: cabut di Profil → API key, lalu buat baru. Jangan simpan key di repo.

## Berkas

| Berkas | Isi |
|---|---|
| `rames_mcp.py` | server MCP (stdio, JSON-RPC 2.0, dua tool) |
| `test_rames_mcp.py` | uji mandiri (stub HTTP + handshake/tool call) |
