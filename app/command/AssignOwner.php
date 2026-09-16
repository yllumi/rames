<?php
declare(strict_types=1);

namespace app\command;

use app\library\Auth\OwnershipMigrator;
use app\library\Auth\UserStore;
use app\library\Storage\AppStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migrasi/inspeksi kepemilikan app (database/apps.json).
 *
 *   php webman app:assign-owner [username]
 *
 * Tanpa argumen: entry app yang belum punya owner di-assign ke admin pertama.
 * Dengan argumen: SEMUA app tanpa owner di-assign ke username tersebut.
 * Argumen --list menampilkan peta owner tiap app (untuk audit).
 */
class AssignOwner extends Command
{
    protected static $defaultName = 'app:assign-owner';
    protected static $defaultDescription = 'Assign owner untuk app lama yang belum punya owner';

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::OPTIONAL, 'Username owner tujuan (default: admin pertama)');
        $this->addOption('list', null, InputOption::VALUE_NONE, 'Tampilkan daftar app beserta owner-nya');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $users = new UserStore();
        $apps = new AppStore();

        if ($input->getOption('list')) {
            $names = [];
            foreach ($users->listWithRoles() as $user) {
                $names[(string) $user['id']] = (string) $user['username'] . ' (' . $user['role'] . ')';
            }
            foreach ($apps->all() as $app) {
                $ownerId = (string) ($app['owner_id'] ?? '');
                $members = is_array($app['members'] ?? null) ? count($app['members']) : 0;
                $output->writeln(sprintf(
                    '%-24s owner=%-28s members=%d',
                    (string) ($app['name'] ?? '?'),
                    $ownerId === '' ? '<comment>(belum ada)</comment>' : ($names[$ownerId] ?? $ownerId),
                    $members
                ));
            }
            return Command::SUCCESS;
        }

        $username = trim((string) ($input->getArgument('username') ?? ''));
        $ownerId = null;

        if ($username !== '') {
            $user = $users->findByUsername($username);
            if ($user === null) {
                $output->writeln('<error>User tidak ditemukan: ' . $username . '</error>');
                return Command::FAILURE;
            }
            $ownerId = (string) $user['id'];
        }

        $migrated = (new OwnershipMigrator())->run($ownerId);
        if ($migrated === 0) {
            $output->writeln('<info>Tidak ada app yang perlu di-assign — semua app sudah punya owner.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>' . $migrated . ' app di-assign ke owner baru.</info>');
        return Command::SUCCESS;
    }
}
