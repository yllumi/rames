# 2. Diagram Arsitektur (Phase 1)

> Bagian dari [dokumentasi arsitektur](../ARCHITECTURE.md) · Spesifikasi: [SPECS.md](../SPECS.md) · Panduan operasional: [DETAILS.md](../../DETAILS.md)

```mermaid
flowchart TB
    subgraph Host
        N[Nginx native<br/>:80 / :443]
        DNS[DNS wildcard *.APP_DOMAIN]
        NAvail[/etc/nginx/sites-available]
        NEnab[/etc/nginx/sites-enabled]
        WH[Watcher host - inotifywait + nginx -s reload<br/>(belum dipasang)]
    end

    subgraph Dashboard[rames-webman container]
        App[Webman HTTP]
        Lib[app/library - logika bisnis]
        DClient[DockerClient - Engine API]
        DCRun[DockerComposeRunner - CLI compose]
        NGGen[NginxConfigGenerator]
        GSvc[GitService]
        Worker[cli/deploy.php - background worker]
    end

    Daemon[(Docker Engine host)]

    App --> Lib
    App -- spawn detached --> Worker
    Lib --> GSvc
    Lib --> DClient
    Lib --> DCRun
    Lib --> NGGen
    Worker --> DCRun
    Worker --> DClient
    Worker --> NGGen
    DClient -- unix socket --> Daemon
    DCRun -- unix socket --> Daemon
    Daemon --> AppA[App A containers]
    Daemon --> AppB[App B containers]
    NGGen -- tulis .conf --> NAvail
    NAvail -. symlink .-> NEnab
    NAvail -- notifikasi perubahan --> WH
    WH -- reload --> N
    DNS -.-> N
    N -- proxy_pass 127.0.0.1:host_port --> AppA
    N -- proxy_pass 127.0.0.1:host_port --> AppB
```

**Poin kunci:**
- Dashboard **tidak** menjalankan `nginx -s reload` langsung ke host via shell — ia hanya menulis file `.conf` ke direktori yang di-mount; reload diaktifkan watcher host (`nginx -t && nginx -s reload`, SPECS §8.3) atau, sementara watcher belum ada, via helper container `--network host --pid host` yang me-chroot ke root host pada Docker socket (`NginxReloader`, SPECS §8.4).
- Semua operasi Docker dijalankan lewat `/var/run/docker.sock` yang di-mount → berjalan pada **daemon Docker host** (bukan daemon di dalam container).

#### Diagram multi-server (F0–F4)

Satu kode, satu image, dua peran runtime (`RAMES_MODE`). Control plane tidak pernah mengeksekusi app milik server agent di hostnya sendiri.

```mermaid
flowchart TD
    subgraph CP["Server #1 — control plane (RAMES_MODE=dashboard)"]
        UI[View + Controller<br/>Webman HTTP :8787]
        RES[ServerResolver + DeployerFactory]
        DB[(SQLite rames.sqlite<br/>users, servers, apps, billing)]
        AW[cli/deploy.php worker lokal]
        LCP[LocalDeployer]
        AW --> LCP
    end

    subgraph A1["Server agent #2..N (RAMES_MODE=agent)"]
        AG[webman HTTP + TLS<br/>listener AGENT_BIND :8443]
        AUTH[AgentAuthMiddleware global<br/>token+timestamp+nonce+signature]
        AJ[AgentController / AgentJobController<br/>AgentAppProjection + AgentArtifactMaterializer]
        AGW[worker cli/deploy.php detached<br/>RAMES_JOB_ID]
        LG[LocalDeployer + app/library yang sama]
        ADB[(SQLite agent<br/>proyeksi app + agent-jobs)]
        AG --> AUTH --> AJ
        AJ -- spawn detached --> AGW --> LG
        AJ --- ADB
    end

    UI --> RES --> DB
    RES -->|server_id=local| LCP
    RES -->|"HTTP JSON ≤30 s<br/>token + pinning TLS"| AG
    AW -.->|POST /jobs, GET /jobs/{id}, /jobs/{id}/log| AG
    A1 -- "state eksekusi (pull)" --> RES
```

- **Control plane**: `resolveCreateServer()` (create app) + `DeployerFactory::createForApp()` (semua operasi) menentukan server; app ber-`server_id` agent selalu lewat `AgentDeployer`.
- **Agent**: hanya route mesin yang didaftarkan (tanpa UI); `AgentJobController` memproyeksikan app (`AgentAppProjection`), mematerialisasi artefak (`AgentArtifactMaterializer`), lalu spawn worker lokal.
- **Dua basis data terpisah** (metadata di control plane, proyeksi + state eksekusi di agent) — kepemilikan data per bidang di §5.25.
- **Op per server bukan hanya deploy** (F2–F4): Nginx/SSL (§5.27), log/terminal (§5.28), file manager (§5.29), monitoring (§5.30), database/Adminer + network/volume (§5.31), dan data backup/restore/dump (§5.32) semuanya dieksekusi **di server pemilik resource** lewat route agent / job — tidak ada jalur mundur ke host control plane.
