<?php

declare(strict_types=1);

namespace App\Console;

use App\Api\Auth\ApiCredentialService;
use App\Api\Auth\ApiClientRegistration;
use App\Api\Auth\ApiTokenIssue;
use Nette\Database\Connection;
use Nette\Database\Row;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'api:create-client', description: 'Vytvoří API klienta a jednorázově zobrazí jeho token.')]
final class CreateApiClientCommand extends Command
{
    public function __construct(
        private readonly Connection $database,
        private readonly ApiCredentialService $credentials,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('actor-email', InputArgument::REQUIRED, 'E-mail aktivního administrátora, který klienta vytváří.');
        $this->addArgument('name', InputArgument::REQUIRED, 'Název zařízení nebo integrace.');
        $this->addArgument('type', InputArgument::REQUIRED, 'Typ: runner, mcp nebo integration.');
        $this->addOption('scope', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Povolený scope; volbu lze opakovat.');
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Platnost tokenu ve dnech (1 až 365).', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->database->fetch(
            'SELECT id FROM users WHERE email = ? AND role = ? AND deactivated_at IS NULL',
            mb_strtolower(trim((string) $input->getArgument('actor-email'))),
            'admin',
        );
        if (!$actor instanceof Row) {
            $output->writeln('<error>Aktivní administrátor nebyl nalezen.</error>');
            return self::INVALID;
        }
        $type = (string) $input->getArgument('type');
        if (!in_array($type, $this->credentials->supportedClientTypes(), true)) {
            $output->writeln('<error>Typ musí být runner, mcp nebo integration.</error>');
            return self::INVALID;
        }
        $scopes = $input->getOption('scope');
        if (!is_array($scopes) || $scopes === [] || array_any(
            $scopes,
            fn (mixed $scope): bool => !is_string($scope) || !in_array($scope, $this->credentials->supportedScopes(), true),
        )) {
            $output->writeln('<error>Uveďte alespoň jeden podporovaný --scope.</error>');
            return self::INVALID;
        }
        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT);
        if (!is_int($days) || $days < 1 || $days > 365) {
            $output->writeln('<error>Platnost musí být 1 až 365 dnů.</error>');
            return self::INVALID;
        }

        try {
            /** @var array{client: ApiClientRegistration, token: ApiTokenIssue} $created */
            $created = $this->database->transaction(function () use ($actor, $input, $type, $scopes, $days): array {
                $client = $this->credentials->createClient((int) $actor['id'], (string) $input->getArgument('name'), $type);
                if ($type === 'runner') {
                    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    $this->database->query('INSERT INTO runner_devices', [
                        'api_client_id' => $client->id,
                        'public_identifier' => $client->publicIdentifier,
                        'name' => (string) $input->getArgument('name'),
                        'device_status' => 'inactive',
                        'created_at' => $now,
                    ]);
                }
                $token = $this->credentials->issueToken(
                    (int) $actor['id'],
                    $client->id,
                    array_values($scopes),
                    new \DateTimeImmutable('+' . $days . ' days', new \DateTimeZone('UTC')),
                );
                return ['client' => $client, 'token' => $token];
            });
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::INVALID;
        }

        $output->writeln('<info>API klient byl vytvořen.</info>');
        $output->writeln('Identifikátor klienta: ' . $created['client']->publicIdentifier);
        $output->writeln('Scopes: ' . implode(', ', $created['token']->scopes));
        $output->writeln('Expirace: ' . $created['token']->expiresAt->format(DATE_ATOM));
        $output->writeln('<comment>Token se zobrazí pouze nyní. Uložte jej do bezpečného úložiště:</comment>');
        $output->writeln($created['token']->token);
        return self::SUCCESS;
    }
}
