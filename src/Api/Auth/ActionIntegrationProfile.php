<?php

declare(strict_types=1);

namespace App\Api\Auth;

final class ActionIntegrationProfile
{
    public static function clientName(bool $applications): string
    {
        return $applications ? 'Application workflow' : 'Todoist action sync';
    }

    /** @return list<string> */
    public static function scopes(bool $applications): array
    {
        return $applications
            ? ['applications:write', 'opportunities:read', 'opportunities:import', 'action_items:read']
            : ['action_items:read', 'action_items:write'];
    }
}
