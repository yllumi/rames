<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tes **perilaku** `public/js/server.js` — polling status job update agen
 * (`GET /servers/{id}/agent-update/status?job=…&offset=…`).
 *
 * Tes statik di `tests/NginxServerViewTest.php` hanya memeriksa sumber; mutasi
 * yang membuat cabang `unreachable` berhenti polling tidak akan tertangkap di
 * sana. Karena itu berkas ini **menjalankan** `public/js/server.js` di Node
 * (`vm.runInContext`) dengan stub minimal:
 *   - `document` (querySelector/querySelectorAll, `hidden`, listener),
 *   - `window` (`fetch`, `setTimeout`/`clearTimeout`, `confirm`, listener),
 *   - fetch palsu dengan antrean respons (JSON teks / penolakan jaringan).
 *
 * Harness ditulis ke direktori temp per skenario (tidak di-commit) dan TIDAK
 * menyentuh store, Docker, HTTP nyata, atau data runtime. Bila binary `node`
 * tidak tersedia, seluruh skenario di-skip.
 *
 * Kontrak yang dijaga (beku):
 *   POST  res `code:0` ⇒ "memantau status", tick pertama 2000 ms;
 *   status `done`/`error` = terminal, `pending`/`running` lanjut;
 *   `unreachable` (reason `not_found` atau transport) **tidak** menghentikan
 *   polling; HTTP 400/404 dan 5 kegagalan jaringan menghentikannya; batas 90
 *   percobaan; teks selalu `textContent` (galat dipotong ≤300); timer dijeda
 *   saat `document.hidden` dan dibersihkan pada `pagehide`.
 */
class ServerJsPollingTest extends TestCase
{
    /** Akar repo (untuk membaca `public/js/server.js`). */
    private const ROOT_DIR = __DIR__ . '/..';

    private static ?string $nodeBinary = null;

    private static bool $nodeProbed = false;

    /**
     * Skenario harness (`argv[3]` di harness Node).
     *
     * @return array<string,array{0:string}>
     */
    public static function pollingScenarios(): array
    {
        return [
            'POST sukses memulai polling 2000 ms' => ['post_ok'],
            'running lalu done menghentikan polling' => ['running_done'],
            'error terminal dipotong dan tetap teks' => ['error_truncated'],
            'HTTP 400 dan 404 menghentikan polling' => ['http_errors'],
            'unreachable tetap polling sampai tick ke-10' => ['unreachable_continues'],
            'unreachable not_found memberi pesan job hilang' => ['unreachable_not_found'],
            'lima kegagalan jaringan berturut-turut' => ['network_failures'],
            'batas 90 percobaan tanpa GET ke-91' => ['attempt_limit'],
            'timer dijeda saat hidden dan bersih saat pagehide' => ['timer_hygiene'],
        ];
    }

    #[DataProvider('pollingScenarios')]
    public function testServerJsPollsAgentUpdateAccordingToContract(string $scenario): void
    {
        $node = $this->nodeBinary();
        if ($node === null) {
            self::markTestSkipped('Binary `node` tidak tersedia — tes perilaku public/js/server.js dilewati.');
        }

        $serverJs = realpath(self::ROOT_DIR . '/public/js/server.js');
        self::assertNotFalse($serverJs, 'public/js/server.js tidak ditemukan');

        $dir = sys_get_temp_dir() . '/rames-server-js-' . bin2hex(random_bytes(6));
        self::assertTrue(@mkdir($dir, 0700), 'gagal membuat direktori temp harness');
        $harness = $dir . '/harness.js';

        $exit = 1;
        $stdout = '';
        $stderr = '';

        try {
            self::assertNotFalse(file_put_contents($harness, self::harnessSource()), 'gagal menulis harness');
            [$exit, $stdout, $stderr] = $this->runCommand([$node, $harness, (string) $serverJs, $scenario], $dir);
        } finally {
            if (is_file($harness)) {
                @unlink($harness);
            }
            @rmdir($dir);
        }

        self::assertSame(
            0,
            $exit,
            'Harness gagal (skenario ' . $scenario . "):\n" . $stdout . $stderr
        );
        self::assertStringContainsString('OK ' . $scenario, $stdout);
    }

    /**
     * Jalankan perintah (array, tanpa shell) dan kembalikan [exit, stdout, stderr].
     *
     * @param list<string> $command
     * @return array{0:int,1:string,2:string}
     */
    private function runCommand(array $command, string $cwd): array
    {
        $pipes = [];
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException('proc_open gagal: ' . implode(' ', $command));
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /** Binary Node untuk harness; `null` ⇒ skenario di-skip. */
    private function nodeBinary(): ?string
    {
        if (self::$nodeProbed) {
            return self::$nodeBinary;
        }
        self::$nodeProbed = true;

        $fromEnv = getenv('NODE_BINARY');
        $candidates = [];
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            $candidates[] = trim($fromEnv);
        }
        foreach (['node', 'nodejs'] as $name) {
            $found = $this->locateInPath($name);
            if ($found !== null) {
                $candidates[] = $found;
            }
        }

        foreach ($candidates as $candidate) {
            try {
                [$exit, $stdout] = $this->runCommand([$candidate, '--version'], self::ROOT_DIR);
            } catch (\RuntimeException) {
                continue;
            }
            if ($exit === 0 && str_starts_with(trim($stdout), 'v')) {
                self::$nodeBinary = $candidate;

                return $candidate;
            }
        }

        return null;
    }

    private function locateInPath(string $binary): ?string
    {
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Harness Node (ditulis ke temp, tidak di-commit).
     *
     * `process.argv[2]` = path `public/js/server.js`, `argv[3]` = nama skenario.
     * Exit 0 + `OK <skenario>` bila semua pemeriksaan lulus; detail kegagalan ke
     * stderr sebagai `FAIL [skenario] ...`.
     */
    private static function harnessSource(): string
    {
        return <<<'JS'
'use strict';

const fs = require('fs');
const vm = require('vm');

const SERVER_JS = process.argv[2];
const SCENARIO = process.argv[3];
const source = fs.readFileSync(SERVER_JS, 'utf8');

let failed = 0;

function check(name, condition, extra) {
  if (condition) {
    return;
  }
  failed += 1;
  console.error('FAIL [' + SCENARIO + '] ' + name + (extra === undefined ? '' : ' :: ' + String(extra)));
}

function childElement() {
  const classes = new Set();
  return {
    textContent: '',
    classes: classes,
    classList: {
      add: function (name) { classes.add(name); },
      remove: function (name) { classes.delete(name); },
      toggle: function (name, force) { if (force) { classes.add(name); } else { classes.delete(name); } }
    }
  };
}

function makeElement(attrs) {
  const classes = new Set();
  const element = {
    attrs: Object.assign({}, attrs || {}),
    textContent: '',
    className: '',
    disabled: false,
    appended: [],
    listeners: {},
    getAttribute: function (name) {
      return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
    },
    setAttribute: function (name, value) { this.attrs[name] = String(value); },
    removeAttribute: function (name) { delete this.attrs[name]; },
    addEventListener: function (type, handler) {
      (this.listeners[type] = this.listeners[type] || []).push(handler);
    },
    appendChild: function (node) { this.appended.push(node); },
    querySelector: function (selector) {
      if (selector === '.spinner-border') { return this.spinner; }
      if (selector === '.js-agent-update-label') { return this.label; }
      return null;
    },
    classList: {
      add: function (name) { classes.add(name); },
      remove: function (name) { classes.delete(name); },
      toggle: function (name, force) { if (force) { classes.add(name); } else { classes.delete(name); } }
    }
  };
  Object.defineProperty(element, 'classes', { value: classes });
  element.spinner = childElement();
  element.label = childElement();
  return element;
}

/** String JSON yang menandai kegagalan jaringan pada stub fetch. */
const NETWORK = 'network-error';

function postOk(jobId) {
  return JSON.stringify({ code: 0, msg: 'Update agen dimulai.', data: { job_id: jobId } });
}

function statusOk(data) {
  return JSON.stringify({ code: 0, data: data });
}

/**
 * Jalankan `server.js` dengan DOM/timer/fetch palsu; `fetches` = antrean respons
 * (string body JSON atau `NETWORK`).
 */
function boot(fetches) {
  const btn = makeElement({ 'data-id': 'vps-1', 'data-name': 'VPS 1', 'data-version': '1.2.3' });
  const box = makeElement({ 'data-server-update': 'vps-1' });
  const badge = makeElement({ 'data-server-status': 'vps-1' });

  const calls = [];
  const timers = [];
  const docListeners = {};
  const winListeners = {};
  const state = { hidden: false };

  const doc = {
    get hidden() { return state.hidden; },
    querySelectorAll: function (selector) {
      if (selector === '.js-server-test') { return []; }
      if (selector === '.js-server-agent-update') { return [btn]; }
      if (selector === '[data-server-update]') { return [box]; }
      if (selector === '[data-server-status]') { return [badge]; }
      return [];
    },
    querySelector: function (selector) {
      if (selector === 'meta[name="csrf-token"]') {
        return { getAttribute: function () { return 'csrf-test-token'; } };
      }
      return null;
    },
    addEventListener: function (type, handler) {
      (docListeners[type] = docListeners[type] || []).push(handler);
    }
  };

  const sandbox = {
    document: doc,
    console: console,
    URLSearchParams: URLSearchParams,
    confirm: function () { return true; },
    fetch: function (url, init) {
      calls.push({ url: url, init: init || {} });
      const next = fetches.shift();
      if (next === undefined) {
        throw new Error('fetch tak terduga: ' + url);
      }
      if (next === NETWORK) {
        return Promise.reject(new Error('jaringan gagal'));
      }
      return Promise.resolve({ text: function () { return Promise.resolve(next); } });
    },
    setTimeout: function (handler, ms) {
      const timer = { handler: handler, ms: ms, cancelled: false, fired: false };
      timers.push(timer);
      return timer;
    },
    clearTimeout: function (timer) { if (timer) { timer.cancelled = true; } }
  };
  sandbox.window = sandbox;
  sandbox.window.addEventListener = function (type, handler) {
    (winListeners[type] = winListeners[type] || []).push(handler);
  };

  vm.createContext(sandbox);
  vm.runInContext(source, sandbox, { filename: SERVER_JS });

  function settle() {
    return new Promise(function (resolve) {
      let rounds = 0;
      (function step() {
        if (rounds >= 8) { resolve(); return; }
        rounds += 1;
        setImmediate(step);
      }());
    });
  }

  function live() {
    return timers.filter(function (timer) { return !timer.cancelled && !timer.fired; });
  }

  async function flush() {
    const timer = live()[0];
    if (!timer) {
      await settle();
      return null;
    }
    timer.fired = true;
    timer.handler();
    await settle();
    return timer;
  }

  return {
    btn: btn,
    box: box,
    badge: badge,
    calls: calls,
    state: state,
    settle: settle,
    live: live,
    flush: flush,
    click: function () {
      (btn.listeners.click || []).forEach(function (handler) { handler(); });
    },
    emitDoc: function (type) {
      (docListeners[type] || []).forEach(function (handler) { handler(); });
    },
    emitWin: function (type) {
      (winListeners[type] || []).forEach(function (handler) { handler(); });
    },
    statusGets: function () {
      return calls.filter(function (call) { return call.url.indexOf('/agent-update/status') !== -1; });
    }
  };
}

const SCENARIOS = {
  /* 1. POST sukses ⇒ pesan "memantau status", tick pertama 2000 ms, satu GET. */
  post_ok: async function () {
    const h = boot([postOk('job-1'), statusOk({ job_id: 'job-1', status: 'running', stage: 'apply', offset: 4 })]);
    h.click();
    await h.settle();

    check('POST dikirim ke endpoint agent-update', h.calls.length === 1 && h.calls[0].url === '/servers/vps-1/agent-update');
    check('token CSRF di body POST', /(^|&)_token=csrf-test-token(&|$)/.test(String(h.calls[0].init.body)));
    check('mode=update di body POST', /(^|&)mode=update(&|$)/.test(String(h.calls[0].init.body)));
    check('pesan mulai + memantau status', h.box.textContent === 'Update agen dimulai (job job-1) — memantau status…', h.box.textContent);
    check('tombol terkunci selama polling', h.btn.disabled === true);
    check('tepat satu timer', h.live().length === 1, String(h.live().length));
    check('tick pertama 2000 ms', h.live()[0].ms === 2000, h.live()[0] && h.live()[0].ms);
    check('belum ada GET sebelum tick', h.statusGets().length === 0);

    await h.flush();
    const gets = h.statusGets();
    check('satu GET status', gets.length === 1, String(gets.length));
    check('URL job + offset=0', gets[0].url === '/servers/vps-1/agent-update/status?job=job-1&offset=0', gets[0].url);
    check('GET tanpa token CSRF', gets[0].init.headers['X-CSRF-TOKEN'] === undefined);
    check('pesan berjalan ⏳', h.box.textContent === '⏳ apply #job-1', h.box.textContent);
  },

  /* 2. running → done ⇒ berhenti, tombol aktif, pesan ✔. */
  running_done: async function () {
    const h = boot([
      postOk('job-2'),
      statusOk({ job_id: 'job-2', status: 'running', stage: 'preflight', message: 'memeriksa repo', log: 'a\nb\n', offset: 12 }),
      statusOk({ job_id: 'job-2', status: 'done', log: '', offset: 12 })
    ]);
    h.click();
    await h.settle();
    await h.flush();

    check('pesan running memakai stage', h.box.textContent === '⏳ preflight #job-2', h.box.textContent);
    check('title memuat message + log', h.box.getAttribute('title') === 'memeriksa repo\na\nb', h.box.getAttribute('title'));
    check('offset lanjutan dikirim', h.statusGets().length === 1);

    await h.flush();
    check('pesan done', h.box.textContent === '✔ Update agen selesai — jalankan Test connection untuk memverifikasi versi.', h.box.textContent);
    check('polling berhenti', h.live().length === 0, String(h.live().length));
    check('tombol aktif kembali', h.btn.disabled === false && h.btn.getAttribute('aria-busy') === 'false');
    check('label tombol normal', h.btn.label.textContent === '⟳ Perbarui agen', h.btn.label.textContent);
    check('status terminal tidak menambah GET', h.statusGets().length === 2, String(h.statusGets().length));
  },

  /* 3. error ⇒ berhenti, ✖ + error ≤300, teks HTML tetap teks. */
  error_truncated: async function () {
    const longError = '<img src=x onerror=alert(1)>' + 'A'.repeat(400);
    const h = boot([postOk('job-3'), statusOk({ job_id: 'job-3', status: 'error', error: longError, offset: 30 })]);
    h.click();
    await h.settle();
    await h.flush();

    const text = h.box.textContent;
    check('prefix ✖', text.indexOf('✖ ') === 0, text.slice(0, 8));
    check('galat dipotong ≤300 karakter', text.length - 2 <= 300, String(text.length - 2));
    check('HTML tetap teks (tidak diparse)', text.indexOf('<img src=x onerror=alert(1)>') === 2, text.slice(0, 40));
    check('tidak ada node anak (tanpa HTML)', h.box.appended.length === 0, String(h.box.appended.length));
    check('innerHTML tidak dipakai', h.box.innerHTML === undefined);
    check('tone galat', h.box.classes.has('text-danger') && !h.box.classes.has('text-muted'));
    check('polling berhenti', h.live().length === 0 && h.btn.disabled === false);
  },

  /* 4. HTTP 400 dan 404 menghentikan polling + pesan server. */
  http_errors: async function () {
    const h400 = boot([postOk('job-4'), JSON.stringify({ code: 400, msg: 'Job tidak sah.' })]);
    h400.click();
    await h400.settle();
    await h400.flush();
    check('400 tampilkan msg', h400.box.textContent === '✖ Job tidak sah.', h400.box.textContent);
    check('400 berhenti', h400.live().length === 0 && h400.btn.disabled === false);

    const h404 = boot([postOk('job-5'), JSON.stringify({ code: 1, error: 'Server tidak ditemukan.' })]);
    h404.click();
    await h404.settle();
    await h404.flush();
    check('404 tampilkan error', h404.box.textContent === '✖ Server tidak ditemukan.', h404.box.textContent);
    check('404 berhenti', h404.live().length === 0 && h404.btn.disabled === false);
  },

  /* 5. unreachable 12 tick ⇒ polling TETAP lanjut, pesan panjang mulai tick 10. */
  unreachable_continues: async function () {
    const unreachable = {
      job_id: 'job-6', status: 'unreachable', reason: 'unreachable', stage: '',
      message: 'Agent tidak terjangkau (kemungkinan sedang restart saat update).',
      error: '', finished_at: '', log: '', offset: 0
    };
    const fetches = [postOk('job-6')];
    for (let i = 0; i < 12; i += 1) {
      fetches.push(statusOk(unreachable));
    }
    const h = boot(fetches);
    h.click();
    await h.settle();

    const seen = [];
    for (let i = 0; i < 12; i += 1) {
      await h.flush();
      seen.push(h.box.textContent);
    }

    check('12 tick ⇒ lebih dari 10 GET', h.statusGets().length > 10, String(h.statusGets().length));
    check('polling TETAP lanjut setelah 12 tick', h.live().length === 1, String(h.live().length));
    check('tombol tetap terkunci', h.btn.disabled === true);
    check('tick 1 pesan pendek (message server)', seen[0] === '⏳ Agent tidak terjangkau (kemungkinan sedang restart saat update).', seen[0]);
    check('tick 9 masih pesan pendek', seen[8].indexOf('Bila berlanjut') === -1, seen[8]);
    check(
      'tick 10 pesan ajakan Test connection',
      seen[9] === '⏳ Agent tidak terjangkau — kemungkinan sedang restart. Bila berlanjut, jalankan Test connection untuk memverifikasi versi.',
      seen[9]
    );
    check('tick 12 tetap pesan panjang', seen[11] === seen[9], seen[11]);
    check('bukan galat (tanpa ✖/text-danger)', seen[11].indexOf('✖') === -1 && !h.box.classes.has('text-danger'));
  },

  /* 6. reason not_found ⇒ pesan job hilang sejak tick 1, polling lanjut. */
  unreachable_not_found: async function () {
    const gone = {
      job_id: 'job-7', status: 'unreachable', reason: 'not_found', stage: '',
      message: 'Agent tidak terjangkau (kemungkinan sedang restart saat update).',
      error: '', finished_at: '', log: '', offset: 0
    };
    const h = boot([postOk('job-7'), statusOk(gone), statusOk(gone)]);
    h.click();
    await h.settle();
    await h.flush();

    check(
      'tick 1 langsung pesan job hilang',
      h.box.textContent === '⏳ Job update tidak ditemukan di agen (kemungkinan agen sudah di-recreate). Jalankan Test connection untuk memverifikasi versi.',
      h.box.textContent
    );
    check('not_found bukan galat', !h.box.classes.has('text-danger') && h.box.textContent.indexOf('✖') === -1);
    check('not_found tetap polling', h.live().length === 1 && h.btn.disabled === true);

    await h.flush();
    check(
      'tick 2 masih pesan job hilang',
      h.box.textContent.indexOf('Job update tidak ditemukan di agen') === 2,
      h.box.textContent
    );
    check('polling lanjut setelah tick 2', h.live().length === 1);
  },

  /* 7. 5 kegagalan jaringan berturut-turut ⇒ berhenti dengan ⚠ (tanpa spam). */
  network_failures: async function () {
    const h = boot([
      postOk('job-8'),
      statusOk({ job_id: 'job-8', status: 'running', stage: 'apply', offset: 9 }),
      NETWORK, NETWORK, NETWORK, NETWORK, NETWORK
    ]);
    h.click();
    await h.settle();
    await h.flush();

    const stable = h.box.textContent;
    for (let i = 0; i < 4; i += 1) {
      await h.flush();
      check('pesan stabil saat gagal sesaat #' + (i + 1), h.box.textContent === stable, h.box.textContent);
    }

    await h.flush();
    check(
      'pesan ⚠ setelah 5 kegagalan',
      h.box.textContent.indexOf('⚠ Gagal memantau status update agen (5 kegagalan jaringan berturut-turut)') === 0,
      h.box.textContent
    );
    check('berhenti setelah 5 kegagalan', h.live().length === 0 && h.btn.disabled === false);
  },

  /* 8. batas 90 percobaan ⇒ tidak ada GET ke-91. */
  attempt_limit: async function () {
    const fetches = [postOk('job-9')];
    for (let i = 0; i < 95; i += 1) {
      fetches.push(statusOk({ job_id: 'job-9', status: 'running', stage: 'apply', offset: i + 1 }));
    }
    const h = boot(fetches);
    h.click();
    await h.settle();

    for (let i = 0; i < 90; i += 1) {
      await h.flush();
    }
    check('tepat 90 GET status', h.statusGets().length === 90, String(h.statusGets().length));
    check('masih ada timer setelah percobaan ke-90', h.live().length === 1);

    await h.flush();
    check(
      'pesan ⏱ setelah batas',
      h.box.textContent === '⏱ Status tidak selesai dipantau — cek log agent (runtime/logs/deploy/agent.log) atau Test connection.',
      h.box.textContent
    );
    check('tidak ada GET ke-91', h.statusGets().length === 90, String(h.statusGets().length));
    check('berhenti setelah batas', h.live().length === 0 && h.btn.disabled === false);
  },

  /* 9. tab tersembunyi menjeda, visibilitychange melanjutkan, pagehide bersih. */
  timer_hygiene: async function () {
    const h = boot([
      postOk('job-10'),
      statusOk({ job_id: 'job-10', status: 'running', stage: 'apply', offset: 1 }),
      statusOk({ job_id: 'job-10', status: 'running', stage: 'apply', offset: 2 })
    ]);
    h.click();
    await h.settle();
    check('timer berjalan setelah POST', h.live().length === 1 && h.live()[0].ms === 2000);

    h.state.hidden = true;
    h.emitDoc('visibilitychange');
    check('timer dibuang saat tab tersembunyi', h.live().length === 0);
    check('tidak ada GET selama tersembunyi', h.calls.length === 1);

    h.state.hidden = false;
    h.emitDoc('visibilitychange');
    check('dijadwalkan segera saat terlihat (0 ms)', h.live().length === 1 && h.live()[0].ms === 0);
    await h.flush();
    check('GET setelah terlihat', h.statusGets().length === 1 && h.calls.length === 2);

    // Tick yang jatuh saat tab masih tersembunyi: tidak menembak & tidak
    // menjadwalkan ulang (dilanjutkan listener visibilitychange).
    h.state.hidden = true;
    await h.flush();
    check('tick saat tersembunyi tidak fetch', h.calls.length === 2, String(h.calls.length));
    check('tick saat tersembunyi tidak menjadwalkan ulang', h.live().length === 0);

    h.state.hidden = false;
    h.emitDoc('visibilitychange');
    await h.flush();
    check('lanjut setelah terlihat kembali', h.statusGets().length === 2, String(h.statusGets().length));
    check('kembali ada timer', h.live().length === 1);

    h.emitWin('pagehide');
    check('pagehide membersihkan timer', h.live().length === 0);
    const before = h.calls.length;
    await h.flush();
    check('tanpa fetch setelah pagehide', h.calls.length === before, String(h.calls.length));
  }
};

(async function main() {
  const scenario = SCENARIOS[SCENARIO];
  if (typeof scenario !== 'function') {
    console.error('skenario tak dikenal: ' + SCENARIO);
    process.exit(2);
  }

  try {
    await scenario();
  } catch (error) {
    console.error('FAIL [' + SCENARIO + '] exception :: ' + (error && error.stack ? error.stack : String(error)));
    process.exit(1);
  }

  if (failed > 0) {
    process.exit(1);
  }

  console.log('OK ' + SCENARIO);
}());
JS;
    }
}
