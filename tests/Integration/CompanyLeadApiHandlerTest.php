<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\Auth\ApiIdentity;
use App\Api\Auth\ApiRequestContext;
use App\Api\V1\ImportOpportunityHandler;
use App\Api\V1\ListOpportunitiesHandler;
use App\Bootstrap;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;
use Tomaj\NetteApi\Response\JsonApiResponse;

final class CompanyLeadApiHandlerTest extends TestCase
{
    public function testApiAcceptsAndFiltersAllOpportunityTypesWithOfferDefault(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $db = $container->getByType(Connection::class);
        $context = $container->getByType(ApiRequestContext::class);
        $import = $container->getByType(ImportOpportunityHandler::class);
        $list = $container->getByType(ListOpportunitiesHandler::class);
        $unique = bin2hex(random_bytes(8));
        $userId = null;
        $ids = [];

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $db->query('INSERT INTO users', [
                'email' => 'lead-api-' . $unique . '@example.test',
                'display_name' => 'Synthetic lead API user',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT),
                'role' => 'admin',
                'locale' => 'cs_CZ',
                'timezone' => 'Europe/Prague',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $db->getInsertId();
            $context->authenticate(new ApiIdentity(1, 1, $userId, 'synthetic-lead-api', 'Synthetic lead API', 'mcp', ['opportunities:read', 'opportunities:import']));

            foreach ([null, 'company_lead', 'tender'] as $type) {
                $body = [
                    'url' => 'https://api-types.example.test/' . ($type ?? 'default') . '/' . $unique,
                    'originalTitle' => 'Synthetic ' . ($type ?? 'default offer'),
                    'originalText' => 'Synthetic content.',
                ];
                if ($type !== null) {
                    $body['opportunityType'] = $type;
                }
                if ($type === 'company_lead') {
                    $body['companyLead'] = ['channel' => 'e-mail', 'contactName' => null];
                }
                $response = $import->handle(['body' => $body]);
                self::assertInstanceOf(JsonApiResponse::class, $response);
                self::assertSame(201, $response->getCode());
                $payload = $response->getPayload();
                self::assertIsArray($payload);
                $ids[$type ?? 'offer'] = $payload['data']['opportunity_id'];
            }

            self::assertSame('offer', $db->fetchField('SELECT opportunity_type FROM opportunities WHERE id = ?', $ids['offer']));
            foreach (['offer', 'company_lead', 'tender'] as $type) {
                $response = $list->handle(['opportunity_type' => $type]);
                self::assertInstanceOf(JsonApiResponse::class, $response);
                $payload = $response->getPayload();
                self::assertIsArray($payload);
                self::assertContains($ids[$type], array_column($payload['data'], 'id'));
                self::assertSame([$type], array_values(array_unique(array_column($payload['data'], 'opportunity_type'))));
            }
        } finally {
            $context->clear();
            foreach ($ids as $id) {
                $db->query("DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id')) = ?", (string) $id);
                $db->query('UPDATE opportunities SET current_source_version_id = NULL WHERE id = ?', $id);
                $db->query('DELETE FROM source_versions WHERE opportunity_id = ?', $id);
                $db->query('DELETE FROM opportunity_sources WHERE opportunity_id = ?', $id);
                $db->query('DELETE FROM opportunities WHERE id = ?', $id);
            }
            if ($userId !== null) {
                $db->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $db->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }
}
