# Rames — paket AGENT (server #2..N)

Server **agent** = server tempat app benar-benar berjalan (Docker Engine + Nginx
native + certbot). Ia dikendalikan dashboard lewat HTTPS di port agent sendiri.
Kode & image-nya **sama** dengan dashboard; yang berbeda hanya mode runtime
(`RAMES_MODE=agent`): route dashboard tidak didaftarkan, timer dashboard tidak
dipasang, dan listener-nya TLS di port agent sendiri (`config/process.php`).

Berkas di direktori ini:

| Berkas | Isi |
|---|---|
| `install.sh` | installer idempoten: rahasia, sertifikat TLS, `docker compose up -d --build`, blok onboarding |
| `../docker-compose.agent.yml` | paket compose **terpisah** (project `rames-agent`, container `rames-agent`, volume `rames-agent-data`) |

Project & nama container berbeda dari dashboard (`rames` / `rames-webman`), jadi
menjalankan paket ini di host yang sama dengan dashboard **aman** — jangan pernah
`docker compose down` project lain dari sini.

## Prasyarat (disiapkan manual, di luar dashboard)

1. Docker Engine + plugin `docker compose` **v2.6+** (Compose override port
   memakai tag `!reset`). User yang menjalankan installer harus bisa bicara
   dengan daemon (grup `docker`, atau jalankan installer sebagai root).
2. `openssl` (token agent + sertifikat TLS self-signed).
3. Nginx native di host + `certbot` (dipakai saat app di server ini melayani
   domain & SSL). Keduanya boleh dipasang setelah agent hidup.
4. Wildcard DNS `*.<APP_DOMAIN>` mengarah ke IP server ini (keputusan 4).
5. Repo ini di-checkout di server ini (mis. `/srv/rames-agent`).

Installer **tidak memasang paket apa pun** dan **tidak mengedit `nginx.conf`**
(hanya memperingatkan bila `sites-enabled/*` belum di-`include`).

## Langkah

```bash
git clone <repo> /srv/rames-agent && cd /srv/rames-agent
./agent/install.sh --domain domain2.com --port-range 30000 30999
```

Apa yang terjadi:

1. prasyarat diperiksa & dilaporkan;
2. dibuat `secrets/agent.env` (0600) + sertifikat TLS self-signed
   `secrets/agent/tls.{crt,key}` (`CN=rames-agent`, SAN `IP:<ip-server>`
   `IP:127.0.0.1`, `DNS:<hostname>`, `DNS:localhost`, 825 hari);
3. `docker compose -f docker-compose.agent.yml -p rames-agent up -d --build`;
4. `GET https://127.0.0.1:8443/version` dipanggil (dengan header token) sebagai
   verifikasi;
5. **blok onboarding** dicetak sekali: URL, token, fingerprint SHA-256, versi
   agent, dan PEM sertifikat di antara penanda
   `-----BEGIN ONBOARDING PEM-----` / `-----END ONBOARDING PEM-----`.

Lalu di dashboard (admin): **Servers → Add Server** → isi name/URL/token +
**paste PEM** di blok onboarding (`APP_DOMAIN`, port range) → **Test connection**.
Dashboard menghitung fingerprint sendiri dari PEM itu (tidak ada peluang salah
ketik) dan menolak tegas bila fingerprint tidak cocok.

### Opsi installer

| Flag | Arti |
|---|---|
| `--domain <d>` | `APP_DOMAIN` server ini. Wajib pada instalasi pertama. |
| `--port-range <s> <e>` | rentang host port container app (default `30000 30999`). |
| `--bind <ip:port>` | `AGENT_BIND` — alamat listen agent **di dalam container** (default `0.0.0.0:8443`). Port selain 8443 hanya boleh bila `AGENT_PORT_PUBLISH` diset sama (lihat di bawah). |
| `--ip <ip>` | IP server ini untuk SAN sertifikat & URL onboarding (pakai bila deteksi otomatis gagal/salah). |
| `--force` | regenerasi token + sertifikat, lalu force-recreate container. **Perbarui server di dashboard setelahnya.** |
| `--help` | bantuan. |

Idempoten: dijalankan ulang tanpa `--force`, token/sertifikat/nilai yang sudah
ada di `secrets/agent.env` dipertahankan (dan blok onboarding dicetak ulang
dengan token yang sama).

## Firewall (wajib)

Port agent (default `8443`) adalah pintu eksekusi Docker di server ini — **batasi
hanya untuk IP control plane**, jangan pernah dibuka ke publik:

```bash
sudo ufw allow from <ip-control-plane> to any port 8443 proto tcp
# atau
sudo iptables -A INPUT -p tcp --dport 8443 -s <ip-control-plane> -j ACCEPT
sudo iptables -A INPUT -p tcp --dport 8443 -j DROP
```

TLS diterminasi **di proses agent** (bukan Nginx host): sertifikat yang di-pin di
dashboard adalah sertifikat agent, bukan sertifikat Nginx (keputusan 6).

## Rahasia & sumber konfigurasi

- `secrets/agent.env` (**0600**, tidak masuk repo — `/secrets/` di `.gitignore`)
  adalah sumber tunggal identitas + rahasia agent: `AGENT_TOKEN`,
  `AGENT_TLS_CERT`, `AGENT_TLS_KEY`, `AGENT_BIND`, `APP_DOMAIN`,
  `PORT_RANGE_START/END`, `RAMES_MODE`. Diinjeksi lewat
  `env_file: ./secrets/agent.env`.
- **Jangan** menambahkan key identitas itu ke blok `environment:` di
  `docker-compose.agent.yml`: Compose selalu memenangkan `environment` atas
  `env_file`, sehingga nilai placeholder (`example.com`, rentang port bawaan)
  akan menimpa nilai nyata dan agent diam-diam salah domain.
- `RAMES_MODE=agent` ditanam literal di compose **dan** di `secrets/agent.env`
  supaya agent tidak pernah bisa start sebagai dashboard penuh.
- Jalur sertifikat ditulis sebagai path absolut host: karena `"${PWD}:${PWD}"`,
  path yang sama valid di host maupun di container.
- Rotasi: `./agent/install.sh --force` → token & sertifikat baru → perbarui di
  dashboard.

## Operasi harian

```bash
# status & log
docker compose -f docker-compose.agent.yml -p rames-agent ps
docker compose -f docker-compose.agent.yml -p rames-agent logs webman --tail 100

# versi (dengan token; -k karena self-signed, atau --cacert secrets/agent/tls.crt)
curl -sS --cacert secrets/agent/tls.crt -H "Authorization: Bearer <token>" https://<ip>:8443/version

# update kode agent (rebuild image yang sama)
git pull && ./agent/install.sh
```

Catatan: `AGENT_PORT_PUBLISH` (port publish di host) hanya dibaca Compose dari
environment/`.env` project, bukan dari `secrets/agent.env` — karena itu selalu
start lewat `./agent/install.sh`. Bila port agent diubah dari 8443, ubah **dua**
tempat agar konsisten: `--bind`/`AGENT_BIND` dan `AGENT_PORT_PUBLISH`.

Database SQLite agent (`/var/lib/rames`, volume `rames-agent-data`) hanya berisi
**proyeksi** app yang ditugaskan — bukan kebenaran, tidak ikut di-backup
dashboard (label volume `rames.role=agent-db`).
