<?php

declare(strict_types=1);

namespace App\Search;

final class CheckStatus
{
    public static function label(string $status): string
    {
        return match ($status) {
            'waiting_for_runner' => 'Čeká na Codex',
            'checking_access' => 'Ověřuje přístup',
            'running' => 'Probíhá',
            'planned' => 'Čeká na kontrolu',
            'waiting_for_login' => 'Vyžaduje přihlášení',
            'resume_requested' => 'Čeká na pokračování',
            'complete' => 'Úplně prověřeno',
            'partial' => 'Částečně prověřeno',
            'error' => 'Kontrola selhala',
            'cancelled' => 'Zrušeno',
            default => 'Stav neznámý',
        };
    }
}
