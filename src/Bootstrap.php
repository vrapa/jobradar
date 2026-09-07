<?php

declare(strict_types=1);

namespace App;

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;
use Nette\Http\Session;
use Symfony\Component\Dotenv\Dotenv;

final class Bootstrap
{
    public function __construct(private readonly string $rootDirectory)
    {
    }

    public function bootWebApplication(): Container
    {
        $container = $this->createContainer(debug: true);
        $environment = getenv('APP_ENV') ?: 'production';
        $session = $container->getByType(Session::class);
        $session->setCookieParameters('/', null, $environment === 'production' ? true : null, 'Lax');

        return $container;
    }

    public function bootConsole(): Container
    {
        return $this->createContainer(debug: false);
    }

    private function createContainer(bool $debug): Container
    {
        $this->loadEnvironment();
        $environment = getenv('APP_ENV') ?: 'production';
        $debugMode = $debug && $environment === 'local'
            && filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOL);
        $runtimeDirectory = getenv('APP_RUNTIME_DIR')
            ?: ($environment === 'local' ? sys_get_temp_dir() . '/jobradar' : $this->rootDirectory . '/var');
        foreach ([$runtimeDirectory, $runtimeDirectory . '/temp', $runtimeDirectory . '/log'] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Nelze vytvořit runtime adresář %s.', $directory));
            }
        }

        $configurator = new Configurator();
        $configurator->setDebugMode($debugMode);
        $configurator->setTempDirectory($runtimeDirectory . '/temp');
        if ($debug) {
            $configurator->enableTracy($runtimeDirectory . '/log');
        }
        $configurator->createRobotLoader()
            ->addDirectory($this->rootDirectory . '/src')
            ->register();
        $configurator->addStaticParameters([
            'app' => [
                'environment' => $environment,
                'url' => getenv('APP_URL') ?: 'http://localhost',
                'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Prague',
            ],
            'database' => [
                'host' => getenv('DB_HOST') ?: 'db',
                'port' => getenv('DB_PORT') ?: '3306',
                'name' => getenv('DB_NAME') ?: 'jobradar',
                'user' => getenv('DB_USER') ?: 'jobradar',
                'password' => getenv('DB_PASSWORD') ?: '',
            ],
        ]);
        $configurator->addConfig($this->rootDirectory . '/config/common.neon');
        $configurator->addConfig($this->rootDirectory . '/config/services.neon');

        return $configurator->createContainer();
    }

    private function loadEnvironment(): void
    {
        $environmentFile = $this->rootDirectory . '/.env';
        if (is_file($environmentFile)) {
            (new Dotenv())->usePutenv()->loadEnv($environmentFile, overrideExistingVars: false);
        }
        date_default_timezone_set('UTC');
    }
}
