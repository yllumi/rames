<?php
declare(strict_types=1);

namespace app\command;

use app\library\Auth\UserStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provisioning awal: buat user admin pertama (SPECS.md §6.3).
 *
 *   php webman make:admin [username] [password]
 *
 * Jika password tidak diberikan, password acak di-generate dan dicetak.
 */
class MakeAdmin extends Command
{
    protected static $defaultName = 'make:admin';
    protected static $defaultDescription = 'Membuat user admin pertama (provisioning awal auth.json)';

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::OPTIONAL, 'Username admin (default: admin)');
        $this->addArgument('password', InputArgument::OPTIONAL, 'Password admin (default: acak)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = new UserStore();

        if (count($store->all()) > 0) {
            $output->writeln('<error>auth.json sudah punya user. Gunakan halaman "Manage Users" di dashboard untuk menambah user.</error>');
            return Command::SUCCESS;
        }

        $username = $input->getArgument('username') ?: 'admin';
        $password = (string) ($input->getArgument('password') ?: '');
        if ($password === '') {
            // Password default di-generate dan dicetak satu kali (SPECS.md §6.3)
            $password = bin2hex(random_bytes(6));
        }

        try {
            $user = $store->create($username, $password);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('<info>User admin berhasil dibuat.</info>');
        $output->writeln('<info>  username : ' . $user['username'] . '</info>');
        $output->writeln('<info>  password : ' . $password . '</info>');
        $output->writeln('');
        $output->writeln('<comment>Segera ganti password lewat halaman "Manage Users" di dashboard.</comment>');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
