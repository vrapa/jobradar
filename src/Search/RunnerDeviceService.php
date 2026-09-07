<?php

declare(strict_types=1);

namespace App\Search;

use App\Api\Auth\ApiIdentity;
use Nette\Database\Connection;
use Nette\Database\Row;

final class RunnerDeviceService
{
    public function __construct(private readonly Connection $database)
    {
    }

    public function requireDeviceId(ApiIdentity $identity): int
    {
        if ($identity->clientType !== 'runner') {
            throw new \InvalidArgumentException('API klient není registrovaný runner.');
        }
        $device = $this->database->fetch(
            'SELECT id FROM runner_devices WHERE api_client_id = ? AND revoked_at IS NULL',
            $identity->clientId,
        );
        if (!$device instanceof Row) {
            throw new \InvalidArgumentException('Runner zařízení není aktivní nebo neexistuje.');
        }
        return (int) $device['id'];
    }
}
