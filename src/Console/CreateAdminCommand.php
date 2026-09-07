<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\AuditLogger;
use App\Security\PasswordPolicy;
use Nette\Database\Explorer;
use Nette\Security\Passwords;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\QuestionHelper;
use Nette\Database\Table\ActiveRow;

#[AsCommand(name: 'user:create-admin', description: 'Bezpečně vytvoří první administrátorský účet.')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly Explorer $database,
        private readonly Passwords $passwords,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Přihlašovací e-mail.');
        $this->addArgument('display-name', InputArgument::OPTIONAL, 'Zobrazované jméno.', 'Administrátor');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>E-mail nemá platný formát.</error>');
            return self::INVALID;
        }
        if ($this->database->table('users')->where('email', $email)->fetch() !== null) {
            $output->writeln('<error>Účet s tímto e-mailem už existuje.</error>');
            return self::FAILURE;
        }

        $question = new Question('Heslo: ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $questionHelper = $this->getHelper('question');
        if (!$questionHelper instanceof QuestionHelper) {
            throw new \LogicException('Question helper není dostupný.');
        }
        $password = (string) $questionHelper->ask($input, $output, $question);
        try {
            $this->passwordPolicy->validate($password);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::INVALID;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $row = $this->database->table('users')->insert([
            'email' => $email,
            'display_name' => trim((string) $input->getArgument('display-name')),
            'password_hash' => $this->passwords->hash($password),
            'role' => 'admin',
            'locale' => 'cs_CZ',
            'timezone' => 'Europe/Prague',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$row instanceof ActiveRow) {
            throw new \RuntimeException('Vytvořený účet nelze načíst.');
        }
        $this->auditLogger->record('user.admin_created', (int) $row['id']);
        $output->writeln('<info>Administrátorský účet byl vytvořen.</info>');
        return self::SUCCESS;
    }
}
