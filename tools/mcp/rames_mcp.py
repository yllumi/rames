#!/usr/bin/env python3
"""Rames MCP server (stdio, JSON-RPC 2.0) — hanya dua tool.

Tool yang disediakan (memetakan langsung ke API eksternal Rames):

  * ``rames_app_status``  → ``GET  /api/v1/apps/{slug}``
  * ``rames_build_app``   → ``POST /api/v1/apps/{slug}/build``

Keduanya memakai **API key per user** (Authorization: Bearer) yang dibuat di
dashboard: Profil → API key. Tidak ada sesi, cookie, atau CSRF.

Konfigurasi (environment, tidak pernah dicetak ke log):

  RAMES_URL      alamat dashboard, mis. ``http://localhost:8123``
                 (default ``http://localhost:8000``). Garis miring di akhir
                 dinormalkan — ``//api/...`` membuat server membalas 404.
  RAMES_API_KEY  API key per user (``rmk_...``). Tanpa ini tool menolak jalan.
  RAMES_TIMEOUT  timeout HTTP detik (default 30, di-cap 30).

Tanpa dependensi pihak ketiga (Python ≥ 3.9, hanya pustaka standar), supaya
tidak menambah runtime/build step ke repo PHP ini. Dijalankan oleh klien MCP
sebagai subprocess stdio, mis.:

    python3 tools/mcp/rames_mcp.py

Pesan protocol: satu objek JSON per baris di stdout (newline-delimited),
diagnostik ke stderr. API key tidak pernah ditulis ke stdout/stderr.
"""

from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

SERVER_NAME = "rames"
SERVER_VERSION = "1.0.0"
PROTOCOL_VERSION = "2025-06-18"
SUPPORTED_PROTOCOLS = {"2024-11-05", "2025-03-26", "2025-06-18"}

DEFAULT_BASE = "http://localhost:8000"
DEFAULT_TIMEOUT = 30
MAX_TIMEOUT = 30

# Slug app = nama app di dashboard (`apps.name`): huruf kecil/angka/strip,
# maks 63, tidak diawali/diakhiri strip. Divalidasi SEBELUM menyusun URL agar
# tidak bisa dipakai untuk keluar dari path (/api/v1/apps/../..).
SLUG_RE = re.compile(r"^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$")
MAX_SLUG_LENGTH = 63

TOOL_STATUS = "rames_app_status"
TOOL_BUILD = "rames_build_app"


class ConfigError(RuntimeError):
    """Konfigurasi server tidak lengkap/tidak sah (tanpa membocorkan secret)."""


class ApiResult:
    def __init__(self, status: int, content_type: str, body: str) -> None:
        self.status = status
        self.content_type = content_type
        self.body = body

    def json_body(self) -> Any:
        try:
            return json.loads(self.body)
        except (ValueError, TypeError):
            return None


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """Jangan ikut redirect: header Authorization tidak boleh bocor ke host lain."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: ANN001, D102
        return None


def _log(message: str) -> None:
    """Diagnostik ke stderr (stdout khusus protocol)."""
    try:
        sys.stderr.write("rames-mcp: " + message + "\n")
        sys.stderr.flush()
    except Exception:  # pragma: no cover - stderr tertutup
        pass


def base_url() -> str:
    raw = (os.environ.get("RAMES_URL") or DEFAULT_BASE).strip()
    parsed = urllib.parse.urlparse(raw)
    if parsed.scheme not in ("http", "https") or not parsed.netloc:
        raise ConfigError(
            "RAMES_URL tidak valid: harus http(s)://host[:port] tanpa path, "
            "mis. http://localhost:8123"
        )
    # Normalkan: buang garis miring di akhir supaya tidak menjadi "//api/...".
    return raw.rstrip("/")


def api_key() -> str:
    key = (os.environ.get("RAMES_API_KEY") or "").strip()
    if key == "":
        raise ConfigError(
            "RAMES_API_KEY belum diisi. Buat key di dashboard: Profil → API key, "
            "lalu set environment RAMES_API_KEY untuk proses MCP ini."
        )
    return key


def timeout_seconds() -> int:
    raw = (os.environ.get("RAMES_TIMEOUT") or "").strip()
    if raw == "":
        return DEFAULT_TIMEOUT
    try:
        value = int(raw)
    except ValueError:
        return DEFAULT_TIMEOUT
    if value <= 0:
        return DEFAULT_TIMEOUT
    return min(value, MAX_TIMEOUT)


def normalize_slug(value: Any) -> str:
    if not isinstance(value, str):
        raise ValueError('Parameter "slug" wajib berupa string (nama app di dashboard).')
    slug = value.strip().lower()
    if slug == "":
        raise ValueError('Parameter "slug" wajib diisi.')
    if len(slug) > MAX_SLUG_LENGTH:
        raise ValueError(f'Parameter "slug" maksimal {MAX_SLUG_LENGTH} karakter.')
    if not SLUG_RE.match(slug):
        raise ValueError(
            'Parameter "slug" hanya boleh huruf kecil a-z, angka, dan strip (-), '
            "serta tidak diawali/diakhiri strip."
        )
    return slug


def call_api(method: str, path: str) -> ApiResult:
    """Panggil API Rames. `HTTPError` (4xx/5xx) dikembalikan sebagai ApiResult."""
    url = base_url() + path
    request = urllib.request.Request(url, method=method)
    request.add_header("Authorization", "Bearer " + api_key())
    request.add_header("Accept", "application/json")
    if method == "POST":
        # Endpoint build tidak butuh body; Content-Length: 0 eksplisit.
        request.data = b""
        request.add_header("Content-Type", "application/json")

    opener = urllib.request.build_opener(_NoRedirect)
    try:
        with opener.open(request, timeout=timeout_seconds()) as response:
            return ApiResult(
                int(response.status),
                response.headers.get("Content-Type", ""),
                response.read().decode("utf-8", "replace"),
            )
    except urllib.error.HTTPError as error:
        body = ""
        try:
            body = error.read().decode("utf-8", "replace")
        except Exception:  # pragma: no cover - body tak terbaca
            body = ""
        return ApiResult(int(error.code), error.headers.get("Content-Type", "") if error.headers else "", body)
    except urllib.error.URLError as error:
        raise RuntimeError(
            "Tidak bisa menghubungi dashboard Rames (" + base_url() + "): "
            + str(error.reason)
        ) from error
    except TimeoutError as error:
        raise RuntimeError(
            "Timeout menunggu dashboard Rames (" + base_url() + ")."
        ) from error


def _hint_for(result: ApiResult) -> str:
    """Petunjuk singkat untuk kegagalan yang mudah salah dibaca."""
    if result.status == 404 and "json" not in result.content_type.lower():
        return (
            " Balasan 404 non-JSON biasanya berarti PATH tidak cocok (bukan soal "
            "API key): periksa RAMES_URL tanpa garis miring di akhir dan path "
            "/api/v1/apps/{slug} tanpa garis miring."
        )
    if result.status == 401:
        return " API key tidak dikenal/dicabut — buat ulang di Profil → API key."
    if result.status == 409:
        return (
            " 409: app sedang deploying (tunggu selesai) atau nama app dipakai "
            "lebih dari satu app di server berbeda (pakai id di dashboard)."
        )
    if result.status == 402:
        return " 402: saldo kredit user pemilik key tidak cukup untuk memulai build."
    if result.status == 422:
        return " 422: build ditolak (app impor project compose eksternal atau direktori app hilang)."
    return ""


def _format_result(result: ApiResult) -> dict:
    parsed = result.json_body()
    text = json.dumps(parsed, ensure_ascii=False, indent=2) if parsed is not None else result.body.strip()
    ok = 200 <= result.status < 300
    if not ok:
        text = "HTTP " + str(result.status) + "\n" + text + _hint_for(result)

    payload: dict[str, Any] = {
        "content": [{"type": "text", "text": text}],
        "isError": not ok,
    }
    if parsed is not None:
        payload["structuredContent"] = parsed
    return payload


TOOLS: list[dict[str, Any]] = [
    {
        "name": TOOL_STATUS,
        "title": "Rames: status app",
        "description": (
            "Ambil status app Rames berdasarkan NAMA app (slug). Dipakai untuk "
            "polling setelah build: panggil berulang sampai `status` bukan "
            "`deploying`. Balasan memuat status, stage, domain, containers[], dan "
            "entri deploy terakhir. Tidak memuat secret."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "slug": {
                    "type": "string",
                    "description": "Nama app di dashboard (field `name`), mis. `cobates`.",
                    "maxLength": MAX_SLUG_LENGTH,
                    "pattern": "^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$",
                }
            },
            "required": ["slug"],
            "additionalProperties": False,
        },
        "annotations": {"readOnlyHint": True, "idempotentHint": True, "openWorldHint": True},
    },
    {
        "name": TOOL_BUILD,
        "title": "Rames: build ulang app",
        "description": (
            "Jalankan build ulang app (docker compose build + recreate, TANPA git "
            "pull) berdasarkan NAMA app (slug). Sama dengan tombol Rebuild di "
            "dashboard: gerbang kredit, guard app impor, dan penolakan saat app "
            "sedang deploy berlaku. Berjalan ASINKRON — balasan 202 hanya berarti "
            "worker sudah di-spawn; poll `rames_app_status` sampai status bukan "
            "`deploying`."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "slug": {
                    "type": "string",
                    "description": "Nama app di dashboard (field `name`), mis. `cobates`.",
                    "maxLength": MAX_SLUG_LENGTH,
                    "pattern": "^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$",
                }
            },
            "required": ["slug"],
            "additionalProperties": False,
        },
        "annotations": {
            "readOnlyHint": False,
            "destructiveHint": False,
            "idempotentHint": False,
            "openWorldHint": True,
        },
    },
]


def tool_status(arguments: dict) -> dict:
    slug = normalize_slug(arguments.get("slug"))
    return _format_result(call_api("GET", "/api/v1/apps/" + urllib.parse.quote(slug, safe="")))


def tool_build(arguments: dict) -> dict:
    slug = normalize_slug(arguments.get("slug"))
    return _format_result(call_api("POST", "/api/v1/apps/" + urllib.parse.quote(slug, safe="") + "/build"))


HANDLERS = {TOOL_STATUS: tool_status, TOOL_BUILD: tool_build}


def handle_initialize(params: dict) -> dict:
    requested = ""
    if isinstance(params, dict):
        requested = str(params.get("protocolVersion") or "")
    version = requested if requested in SUPPORTED_PROTOCOLS else PROTOCOL_VERSION
    return {
        "protocolVersion": version,
        "capabilities": {"tools": {"listChanged": False}},
        "serverInfo": {"name": SERVER_NAME, "version": SERVER_VERSION},
        "instructions": (
            "Server MCP untuk Rames. Tool `rames_app_status` membaca status app dan "
            "`rames_build_app` memicu build ulang (asinkron). Keduanya memakai API "
            "key per user (Profil → API key) dan bertindak sebagai user pemilik key. "
            "Setelah build, poll status sampai bukan `deploying`."
        ),
    }


def handle_tools_call(params: dict) -> dict:
    name = params.get("name") if isinstance(params, dict) else None
    arguments = params.get("arguments") if isinstance(params, dict) else None
    if not isinstance(name, str) or name not in HANDLERS:
        raise LookupError("Tool tidak dikenal: " + str(name))
    if arguments is None:
        arguments = {}
    if not isinstance(arguments, dict):
        raise ValueError('"arguments" wajib berupa object.')

    return HANDLERS[name](arguments)


def _write(message: dict) -> None:
    sys.stdout.write(json.dumps(message, ensure_ascii=False) + "\n")
    sys.stdout.flush()


def _error(request_id: Any, code: int, message: str) -> None:
    _write({"jsonrpc": "2.0", "id": request_id, "error": {"code": code, "message": message}})


def _result(request_id: Any, result: dict) -> None:
    _write({"jsonrpc": "2.0", "id": request_id, "result": result})


def handle_message(message: dict) -> None:
    method = message.get("method")
    request_id = message.get("id")
    is_notification = request_id is None
    params = message.get("params") if isinstance(message.get("params"), dict) else {}

    if method == "initialize":
        if not is_notification:
            _result(request_id, handle_initialize(params))
        return

    if method in ("notifications/initialized", "initialized"):
        return  # notifikasi: tanpa balasan

    if method == "ping":
        if not is_notification:
            _result(request_id, {})
        return

    if method == "tools/list":
        if not is_notification:
            _result(request_id, {"tools": TOOLS})
        return

    if method == "tools/call":
        try:
            payload = handle_tools_call(params)
        except LookupError as error:
            if not is_notification:
                _error(request_id, -32602, str(error))
            return
        except ValueError as error:
            # Argumen tidak sah: kegagalan tool, bukan protocol error.
            if not is_notification:
                _result(request_id, {
                    "content": [{"type": "text", "text": str(error)}],
                    "isError": True,
                })
            return
        except ConfigError as error:
            if not is_notification:
                _result(request_id, {
                    "content": [{"type": "text", "text": str(error)}],
                    "isError": True,
                })
            return
        except RuntimeError as error:
            if not is_notification:
                _result(request_id, {
                    "content": [{"type": "text", "text": str(error)}],
                    "isError": True,
                })
            return
        if not is_notification:
            _result(request_id, payload)
        return

    if method in ("resources/list", "prompts/list"):
        if not is_notification:
            key = "resources" if method == "resources/list" else "prompts"
            _result(request_id, {key: []})
        return

    if is_notification:
        return
    _error(request_id, -32601, "Method tidak didukung: " + str(method))


def main() -> int:
    if hasattr(sys.stdout, "reconfigure"):
        try:
            sys.stdout.reconfigure(encoding="utf-8", newline="\n")
            sys.stderr.reconfigure(encoding="utf-8", newline="\n")
        except Exception:  # pragma: no cover
            pass

    _log("siap (stdio) — RAMES_URL=" + os.environ.get("RAMES_URL", DEFAULT_BASE))

    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        try:
            message = json.loads(line)
        except ValueError:
            _error(None, -32700, "JSON tidak valid")
            continue
        if not isinstance(message, dict):
            _error(None, -32600, "Request tidak valid")
            continue
        try:
            handle_message(message)
        except Exception as error:  # pragma: no cover - jaring pengaman
            _log("gagal memproses pesan: " + type(error).__name__)
            _error(message.get("id"), -32603, "Kesalahan internal server MCP")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:  # pragma: no cover
        sys.exit(0)
