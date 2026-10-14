<?php
declare(strict_types=1);

namespace Tests;

use app\controller\TerminalController;
use app\library\Deploy\AgentDeployer;
use app\library\Docker\DockerExec;

/**
 * Subclass `TerminalController` untuk unit test kebijakan user terminal
 * TANPA Docker, `apps.json`, sesi, atau penulisan audit ke `runtime/logs` nyata.
 *
 * Yang di-override hanya seam infrastruktur (`dockerExec()`, `requireApp()`,
 * `audit()`); logika produksi (resolve user, penerusan ke DockerExec) tetap
 * dijalankan apa adanya.
 */
class FakeTerminalController extends TerminalController
{
    /** @var array<int,array{app:string,container:string,action:string}> */
    public array $audits = [];

    public function __construct(private DockerExec $exec)
    {
    }

    protected function dockerExec(): DockerExec
    {
        return $this->exec;
    }

    /**
     * Selalu server lokal: uji ini menguji jalur `DockerExec` lokal, bukan jalur
     * proxy agent (itu di `LogTerminalServerProxyTest`). Tanpa override ini,
     * implementasi produksi akan membaca registry server nyata (`database/`).
     */
    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return null;
    }

    /**
     * App tiruan: cukup punya `containers` agar `AppContainers::resolve()` cocok
     * tanpa menyentuh Engine/AppStore.
     */
    protected function requireApp(string $id): array
    {
        return [
            'id' => $id,
            'name' => 'demo',
            'containers' => [['container_name' => 'demo-web-1']],
        ];
    }

    protected function audit(array $app, string $container, string $action): void
    {
        $this->audits[] = [
            'app' => (string) ($app['name'] ?? ''),
            'container' => $container,
            'action' => $action,
        ];
    }
}
