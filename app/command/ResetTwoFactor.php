<?php
declare(strict_types=1);

namespace app\command;

use app\library\Auth\UserStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Jalur darurat 2FA (docs/SPECS.md §6.4): nonaktifkan TOTP seorang user dari
 * host bila authenticator **dan** seluruh kode pemulihan hilang — tanpa ini
 * akun terkunci permanen karena tidak ada reset lewat email/UI.
 *
 *   php webman 2fa:reset [username]
 *
 * Hanya menghapus field 2FA; password dan role tidak diubah. Setelah reset,
 * user login satu langkah lalu dapat mengaktifkan 2FA kembali dari `/profile`.
 */
class ResetTwoFactor extends Command
{
    protected static $defaultName = '2fa:reset';
    protected static $defaultDescription = 'Menonaktifkan 2FA TOTP seorang user (jalur darurat)';

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Username yang 2FA-nya dinonaktifkan');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = trim((string) $input->getArgument('username'));
        if ($username === '') {
            $output->writeln('<error>Username wajib diisi.</error>');

            return Command::FAILURE;
        }

        $store = $this->users();
        $user = $store->findByUsername($username);
        if ($user === null) {
            $output->writeln('<error>User "' . $username . '" tidak ditemukan.</error>');

            return Command::FAILURE;
        }

        if (!UserStore::totpEnabled($user)) {
            $output->writeln('<comment>2FA user "' . $username . '" memang tidak aktif.</comment>');

            return Command::SUCCESS;
        }

        try {
            $store->disableTotp((string) $user['id']);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>2FA user "' . $username . '" dinonaktifkan.</info>');
        $output->writeln('<comment>Password tidak berubah. Segera login dan aktifkan ulang 2FA dari /profile.</comment>');

        return Command::SUCCESS;
    }

    /**
     * Seam pengujian: test menimpa metode ini agar tidak menyentuh basis data
     * dashboard nyata (pola yang sama dipakai `UserStore($path)`).
     */
    protected function users(): UserStore
    {
        return new UserStore();
    }
}
