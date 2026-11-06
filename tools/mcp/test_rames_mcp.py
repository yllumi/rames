#!/usr/bin/env python3
"""Uji mandiri server MCP Rames (`rames_mcp.py`) — tanpa dependensi.

Menjalankan stub HTTP lokal, lalu menggerakkan server MCP lewat stdio persis
seperti klien MCP (JSON-RPC per baris). Yang dibuktikan:

  * handshake `initialize` + `tools/list` (dua tool, skema slug);
  * `rames_app_status` → 200 (structuredContent) dan 404 JSON (isError + hint);
  * `rames_build_app` → 202 asinkron;
  * `RAMES_URL` berakhiran `/` dinormalkan (path stub tetap `/api/v1/...`);
  * slug berbahaya (`../x`) ditolak SEBELUM request;
  * `RAMES_API_KEY` kosong → isError tanpa memanggil jaringan;
  * 404 non-JSON (gejala garis miring ganda) diberi petunjuk path.

Jalankan:  python3 tools/mcp/test_rames_mcp.py
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

SERVER = Path(__file__).with_name("rames_mcp.py")
TEST_KEY = "rmk_unittest_key_tidak_dipakai_di_produksi"


class StubHandler(BaseHTTPRequestHandler):
    """Stub API `/api/v1/apps/{slug}[/build]` dengan respons yang bisa diatur."""

    protocol_version = "HTTP/1.1"

    def log_message(self, *args):  # noqa: D102, ANN002 - senyap
        return

    def _record(self) -> None:
        self.server.requests.append(  # type: ignore[attr-defined]
            {
                "method": self.command,
                "path": self.path,
                "authorization": self.headers.get("Authorization", ""),
            }
        )

    def _send(self, status: int, body: str, content_type: str) -> None:
        payload = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def do_GET(self):  # noqa: N802
        self._record()
        mode = self.server.mode  # type: ignore[attr-defined]
        if mode == "html404":
            self._send(404, "<!doctype html><h1>404 Not Found</h1>", "text/html;charset=utf-8")
            return
        if mode == "unauthorized":
            self._send(401, json.dumps({"code": 401, "error": "Unauthorized", "reason": "unauthorized"}), "application/json")
            return
        if self.path != "/api/v1/apps/cobates":
            self._send(404, json.dumps({"code": 404, "error": "App tidak ditemukan.", "reason": "not_found"}), "application/json")
            return
        self._send(200, json.dumps({
            "code": 0,
            "data": {
                "slug": "cobates",
                "id": "05a1414ca87c5f89",
                "server_id": "local",
                "status": "running",
                "stage": None,
                "message": "Running",
                "domain": "cobates.contoh.id",
                "containers": [],
                "last_deploy": None,
            },
        }), "application/json")

    def do_POST(self):  # noqa: N802
        self._record()
        length = int(self.headers.get("Content-Length") or 0)
        if length:
            self.rfile.read(length)
        if self.path != "/api/v1/apps/cobates/build":
            self._send(404, json.dumps({"code": 404, "error": "App tidak ditemukan.", "reason": "not_found"}), "application/json")
            return
        self._send(202, json.dumps({
            "code": 0,
            "data": {
                "slug": "cobates",
                "id": "05a1414ca87c5f89",
                "status": "deploying",
                "stage": "queued",
                "message": "Rebuild dimulai.",
                "poll_url": "/api/v1/apps/cobates",
            },
        }), "application/json")


class McpServerProcess:
    """Bungkus proses server MCP: kirim 1 pesan JSON, baca 1 balasan."""

    def __init__(self, base_url: str, key: str | None) -> None:
        env = dict(os.environ)
        env["RAMES_URL"] = base_url
        if key is None:
            env.pop("RAMES_API_KEY", None)
        else:
            env["RAMES_API_KEY"] = key
        self.proc = subprocess.Popen(
            [sys.executable, str(SERVER)],
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            env=env,
        )
        self.next_id = 1

    def request(self, method: str, params: dict | None = None) -> dict:
        message = {"jsonrpc": "2.0", "id": self.next_id, "method": method}
        self.next_id += 1
        if params is not None:
            message["params"] = params
        assert self.proc.stdin is not None
        self.proc.stdin.write(json.dumps(message) + "\n")
        self.proc.stdin.flush()
        assert self.proc.stdout is not None
        line = self.proc.stdout.readline()
        assert line.strip(), "server MCP tidak membalas"
        return json.loads(line)

    def close(self) -> None:
        if self.proc.stdin:
            self.proc.stdin.close()
        try:
            self.proc.wait(timeout=5)
        except subprocess.TimeoutExpired:  # pragma: no cover
            self.proc.kill()
        if self.proc.stdout:
            self.proc.stdout.close()
        if self.proc.stderr:
            self.proc.stderr.close()


class RamesMcpTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.httpd = ThreadingHTTPServer(("127.0.0.1", 0), StubHandler)
        cls.httpd.mode = "normal"  # type: ignore[attr-defined]
        cls.httpd.requests = []  # type: ignore[attr-defined]
        cls.thread = threading.Thread(target=cls.httpd.serve_forever, daemon=True)
        cls.thread.start()
        cls.base = "http://127.0.0.1:" + str(cls.httpd.server_address[1])

    @classmethod
    def tearDownClass(cls) -> None:
        cls.httpd.shutdown()
        cls.httpd.server_close()

    def setUp(self) -> None:
        self.httpd.mode = "normal"  # type: ignore[attr-defined]
        self.httpd.requests.clear()  # type: ignore[attr-defined]

    def client(self, *, base: str | None = None, key: str | None = TEST_KEY) -> McpServerProcess:
        server = McpServerProcess(base or self.base, key)
        self.addCleanup(server.close)
        return server

    # ------------------------------------------------------------------
    # Protocol
    # ------------------------------------------------------------------

    def test_initialize_and_tools_list(self) -> None:
        client = self.client()
        init = client.request("initialize", {"protocolVersion": "2025-06-18", "capabilities": {}})

        self.assertEqual("2.0", init.get("jsonrpc"))
        result = init["result"]
        self.assertEqual("2025-06-18", result["protocolVersion"])
        self.assertEqual("rames", result["serverInfo"]["name"])
        self.assertIn("tools", result["capabilities"])

        listing = client.request("tools/list")
        tools = {tool["name"]: tool for tool in listing["result"]["tools"]}
        self.assertEqual({"rames_app_status", "rames_build_app"}, set(tools))
        for tool in tools.values():
            self.assertEqual(["slug"], tool["inputSchema"]["required"])
            self.assertFalse(tool["inputSchema"]["additionalProperties"])
        self.assertTrue(tools["rames_app_status"]["annotations"]["readOnlyHint"])
        self.assertFalse(tools["rames_build_app"]["annotations"]["readOnlyHint"])

    def test_unknown_method_returns_jsonrpc_error(self) -> None:
        response = self.client().request("tidak/ada")

        self.assertEqual(-32601, response["error"]["code"])

    # ------------------------------------------------------------------
    # Tool: status
    # ------------------------------------------------------------------

    def test_status_returns_structured_content(self) -> None:
        result = self.client().request("tools/call", {"name": "rames_app_status", "arguments": {"slug": "cobates"}})
        payload = result["result"]

        self.assertFalse(payload["isError"])
        self.assertEqual("running", payload["structuredContent"]["data"]["status"])
        self.assertIn("cobates", payload["content"][0]["text"])

        recorded = self.httpd.requests[0]  # type: ignore[attr-defined]
        self.assertEqual("GET", recorded["method"])
        self.assertEqual("/api/v1/apps/cobates", recorded["path"])
        self.assertEqual("Bearer " + TEST_KEY, recorded["authorization"])

    def test_status_slug_is_normalized(self) -> None:
        self.client().request("tools/call", {"name": "rames_app_status", "arguments": {"slug": "  COBATES  "}})

        self.assertEqual("/api/v1/apps/cobates", self.httpd.requests[0]["path"])  # type: ignore[attr-defined]

    def test_status_not_found_json_is_tool_error(self) -> None:
        result = self.client().request("tools/call", {"name": "rames_app_status", "arguments": {"slug": "hantu"}})
        payload = result["result"]

        self.assertTrue(payload["isError"])
        self.assertEqual(404, payload["structuredContent"]["code"])
        self.assertIn("HTTP 404", payload["content"][0]["text"])

    def test_status_unauthorized_hint_mentions_key(self) -> None:
        self.httpd.mode = "unauthorized"  # type: ignore[attr-defined]
        result = self.client().request("tools/call", {"name": "rames_app_status", "arguments": {"slug": "cobates"}})
        payload = result["result"]

        self.assertTrue(payload["isError"])
        self.assertIn("API key", payload["content"][0]["text"])

    def test_status_html_404_hint_mentions_path(self) -> None:
        self.httpd.mode = "html404"  # type: ignore[attr-defined]
        result = self.client().request("tools/call", {"name": "rames_app_status", "arguments": {"slug": "cobates"}})
        text = result["result"]["content"][0]["text"]

        self.assertIn("PATH tidak cocok", text)
        self.assertIn("garis miring", text)

    # ------------------------------------------------------------------
    # Tool: build
    # ------------------------------------------------------------------

    def test_build_posts_and_reports_async(self) -> None:
        result = self.client().request("tools/call", {"name": "rames_build_app", "arguments": {"slug": "cobates"}})
        payload = result["result"]

        self.assertFalse(payload["isError"])
        data = payload["structuredContent"]["data"]
        self.assertEqual("deploying", data["status"])
        self.assertEqual("/api/v1/apps/cobates", data["poll_url"])

        recorded = self.httpd.requests[0]  # type: ignore[attr-defined]
        self.assertEqual("POST", recorded["method"])
        self.assertEqual("/api/v1/apps/cobates/build", recorded["path"])

    # ------------------------------------------------------------------
    # Pengamanan & konfigurasi
    # ------------------------------------------------------------------

    def test_dangerous_slug_is_rejected_before_request(self) -> None:
        result = self.client().request("tools/call", {"name": "rames_app_status", "arguments": {"slug": "../../etc/passwd"}})
        payload = result["result"]

        self.assertTrue(payload["isError"])
        self.assertIn('"slug"', payload["content"][0]["text"])
        self.assertEqual([], self.httpd.requests)  # type: ignore[attr-defined]

    def test_missing_api_key_is_reported_without_network(self) -> None:
        result = self.client(key=None).request("tools/call", {"name": "rames_app_status", "arguments": {"slug": "cobates"}})
        text = result["result"]["content"][0]["text"]

        self.assertTrue(result["result"]["isError"])
        self.assertIn("RAMES_API_KEY", text)
        self.assertEqual([], self.httpd.requests)  # type: ignore[attr-defined]

    def test_base_with_trailing_slash_is_normalized(self) -> None:
        client = self.client(base=self.base + "/")
        result = client.request("tools/call", {"name": "rames_app_status", "arguments": {"slug": "cobates"}})

        self.assertFalse(result["result"]["isError"])
        self.assertEqual("/api/v1/apps/cobates", self.httpd.requests[0]["path"])  # type: ignore[attr-defined]

    def test_unreachable_host_is_tool_error(self) -> None:
        client = self.client(base="http://127.0.0.1:1")
        result = client.request("tools/call", {"name": "rames_app_status", "arguments": {"slug": "cobates"}})

        self.assertTrue(result["result"]["isError"])
        self.assertIn("Tidak bisa menghubungi", result["result"]["content"][0]["text"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
