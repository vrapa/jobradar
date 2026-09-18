<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Api\Auth\ActionIntegrationProfile;
use PHPUnit\Framework\TestCase;

final class ActionIntegrationProfileTest extends TestCase
{
    public function testApplicationWorkflowCanReadAndCorrectOpportunityContent(): void
    {
        self::assertSame('Application workflow', ActionIntegrationProfile::clientName(true));
        self::assertSame(
            ['applications:write', 'opportunities:read', 'opportunities:import', 'action_items:read'],
            ActionIntegrationProfile::scopes(true),
        );
    }

    public function testTodoistSyncKeepsItsNarrowScopes(): void
    {
        self::assertSame('Todoist action sync', ActionIntegrationProfile::clientName(false));
        self::assertSame(['action_items:read', 'action_items:write'], ActionIntegrationProfile::scopes(false));
    }
}
