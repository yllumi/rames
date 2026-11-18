<?php
declare(strict_types=1);

namespace app\command;

use app\library\Auth\UserStore;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Jalur darurat password (docs/SPECS.md §6.3): operator host mereset password
 * seorang user tanpa harus punya akses ke dashboard dan **tanpa** menghapus
 * user/role/kepemilikan app.
 *
 *   php webman passwd:reset <username> [password]
 *
 * Tanpa argumen password: interaktif (diminta dua kali, tersembunyi) bila
 * terminal mendukung; non-interaktif (mis. `docker exec` tanpa `-t`) ⇒ password
 * acak di-generate & dicetak SEKALI. Password di argumen terlihat di daftar
 * proses (`ps`) selama perintah berjalan — hindari di host bersama.
 *
 * Yang diubah hanya `password_hash`. Role, kepemilikan app, 2FA, dan API key
 * tidak disentuh; sesi login yang sudah ada juga tidak diputus (hapus cookie
 * browser / logout bila perlu).
 */
class ResetPassword extends Command
{
    protected static $defaultName = 'passwd:reset';
    protected static $defaultDescription = 'Mereset password user (jalur darurat host)';

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Username yang password-nya direset');
        $this->addArgument(
            'password',
            InputArgument::OPTIONAL,
            'Password baru (kosong = diminta interaktif / di-generate acak)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = trim((string) $input->getArgument('username'));
        if ($username === '') {
            $output->writeln('<error>Username wajib diisi.</error>');

            return Command::FAILURE;
        }

        $store = $this->users();
        if (count($store->all()) === 0) {
            $output->writeln('<error>Belum ada user di store. Buat admin pertama: php webman make:admin</error>');

            return Command::FAILURE;
        }

        $user = $store->findByUsername($username);
        if ($user === null) {
            $output->writeln('<error>User "' . $username . '" tidak ditemukan.</error>');

            return Command::FAILURE;
        }

        $generated = false;
        $password = (string) ($input->getArgument('password') ?? '');
        if ($password === '') {
            if ($input->isInteractive()) {
                $password = $this->askNewPassword($input, $output);
                if ($password === null) {
                    return Command::FAILURE;
                }
            } else {
                $password = bin2hex(random_bytes(6));
                $generated = true;
            }
        }

        if (strlen($password) < 6) {
            $output->writeln('<error>Password minimal 6 karakter.</error>');

            return Command::FAILURE;
        }

        try {
            $store->changePassword((string) $user['id'], $password);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('<info>Password user "' . $user['username'] . '" berhasil direset.</info>');
        $output->writeln('<info>  role : ' . $store->roleOf($user) . '</info>');
        if ($generated) {
            $output->writeln('<info>  password : ' . $password . ' (tampil SEKALI)</info>');
            $output->writeln('<comment>Simpan sekarang, lalu ganti lewat halaman Profil setelah login.</comment>');
        }
        if (UserStore::totpEnabled($user)) {
            $output->writeln('<comment>Catatan: 2FA user ini tetap aktif — siapkan authenticator/kode pemulihan saat login.</comment>');
        }
        $output->writeln('<comment>Sesi login lama tidak diputus otomatis. Logout di browser lain bila perlu.</comment>');
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Minta password baru dua kali (input tersembunyi). `null` bila gagal/
     * tidak cocok — pesan galat sudah dicetak.
     */
    private function askNewPassword(InputInterface $input, OutputInterface $output): ?string
    {
        // QuestionHelper dipakai langsung (bukan `getHelper('question')`) agar
        // command tetap bisa dijalankan lewat `CommandTester` tanpa HelperSet.
        $helper = new QuestionHelper();

        try {
            $first = $this->askHidden($helper, $input, $output, 'Password baru: ');
            $second = $this->askHidden($helper, $input, $output, 'Ulangi password baru: ');
        } catch (RuntimeException $e) {
            $output->writeln(
                '<error>Terminal tidak mendukung input tersembunyi (' . $e->getMessage() . ').</error>'
                . ' Jalankan dengan `docker exec -it ...` atau berikan password sebagai argumen'
                . ' (terlihat di daftar proses).'
            );

            return null;
        }

        if ($first === '') {
            $output->writeln('<error>Password tidak boleh kosong.</error>');

            return null;
        }
        if ($first !== $second) {
            $output->writeln('<error>Password tidak sama — tidak ada yang diubah.</error>');

            return null;
        }

        return $first;
    }

    private function askHidden(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $prompt
    ): string
    {
        $question = new Question($prompt);
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        /** @var mixed $answer */
        $answer = $helper->ask($input, $output, $question);

        return is_scalar($answer) ? (string) $answer : '';
    }

    /**
     * Seam pengujian: test menimpa metode ini agar tidak menyentuh basis data
     * dashboard nyata (pola yang sama dipakai `ResetTwoFactor`).
     */
    protected function users(): UserStore
    {
        return new UserStore();
    }
}
