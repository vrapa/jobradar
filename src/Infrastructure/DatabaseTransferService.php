<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Nette\Database\Connection;

final class DatabaseTransferService
{
    private readonly int $port;

    public function __construct(
        private readonly Connection $database,
        private readonly string $host,
        string $port,
        private readonly string $databaseName,
        private readonly string $user,
        private readonly string $password,
    ) {
        if (preg_match('/^\d{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535
            || trim($this->databaseName) === '' || trim($this->user) === ''
        ) {
            throw new \InvalidArgumentException('Konfigurace databázového přenosu není platná.');
        }
        $this->port = (int) $port;
    }

    public function backup(string $path): string
    {
        $target = self::newTargetPath($path);
        $temporary = dirname($target) . DIRECTORY_SEPARATOR . '.' . basename($target) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $output = fopen($temporary, 'x+b');
        if (!is_resource($output)) {
            throw new \RuntimeException('Dočasný soubor zálohy nelze vytvořit.');
        }
        @chmod($temporary, 0600);

        try {
            $this->run([
                getenv('JOBRADAR_MYSQLDUMP_BINARY') ?: 'mysqldump',
                '--host=' . $this->host,
                '--port=' . $this->port,
                '--user=' . $this->user,
                '--single-transaction',
                '--quick',
                '--skip-comments',
                '--hex-blob',
                '--default-character-set=utf8mb4',
                '--no-tablespaces',
                $this->databaseName,
            ], null, $output, 'Zálohu databáze se nepodařilo vytvořit.');
        } catch (\Throwable $exception) {
            fclose($output);
            @unlink($temporary);
            throw $exception;
        }
        fclose($output);
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException('Hotovou zálohu se nepodařilo přesunout na cílové místo.');
        }
        return $target;
    }

    public function restore(string $path): void
    {
        $source = realpath($path);
        if ($source === false || !is_file($source) || !is_readable($source)) {
            throw new \InvalidArgumentException('Soubor zálohy neexistuje nebo jej nelze číst.');
        }
        $tableCount = (int) $this->database->fetchField(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?',
            $this->databaseName,
        );
        if ($tableCount !== 0) {
            throw new \InvalidArgumentException('Obnovu lze provést pouze do prázdné databáze.');
        }
        $input = fopen($source, 'rb');
        if (!is_resource($input)) {
            throw new \RuntimeException('Soubor zálohy nelze otevřít.');
        }
        try {
            $this->run([
                getenv('JOBRADAR_MYSQL_BINARY') ?: 'mysql',
                '--host=' . $this->host,
                '--port=' . $this->port,
                '--user=' . $this->user,
                '--default-character-set=utf8mb4',
                '--binary-mode',
                '--database=' . $this->databaseName,
            ], $input, null, 'Databázi se nepodařilo obnovit.');
        } finally {
            fclose($input);
        }
    }

    /** @param list<string> $command
     *  @param resource|null $input
     *  @param resource|null $output
     */
    private function run(array $command, mixed $input, mixed $output, string $failureMessage): void
    {
        $descriptors = [
            0 => is_resource($input) ? $input : ['pipe', 'r'],
            1 => is_resource($output) ? $output : ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $environment = getenv();
        $environment['MYSQL_PWD'] = $this->password;
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException($failureMessage);
        }
        if (!is_resource($input) && isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        if (!is_resource($output) && isset($pipes[1]) && is_resource($pipes[1])) {
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }
        if (proc_close($process) !== 0) {
            throw new \RuntimeException($failureMessage);
        }
    }

    private static function newTargetPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'sql') {
            throw new \InvalidArgumentException('Záloha musí mít cílovou cestu s příponou .sql.');
        }
        $directory = realpath(dirname($path));
        if ($directory === false || !is_dir($directory) || !is_writable($directory)) {
            throw new \InvalidArgumentException('Cílový adresář zálohy neexistuje nebo do něj nelze zapisovat.');
        }
        $target = $directory . DIRECTORY_SEPARATOR . basename($path);
        if (file_exists($target)) {
            throw new \InvalidArgumentException('Cílový soubor už existuje; záloha jej nepřepíše.');
        }
        return $target;
    }
}
