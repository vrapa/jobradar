<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mcp\IdleStdioTransport;
use PHPUnit\Framework\TestCase;

final class IdleStdioTransportTest extends TestCase
{
    private string|false $originalTimeout;

    protected function setUp(): void
    {
        $this->originalTimeout = getenv('JOBRADAR_MCP_IDLE_TIMEOUT_SECONDS');
    }

    protected function tearDown(): void
    {
        if (false === $this->originalTimeout) {
            putenv('JOBRADAR_MCP_IDLE_TIMEOUT_SECONDS');

            return;
        }

        putenv('JOBRADAR_MCP_IDLE_TIMEOUT_SECONDS=' . $this->originalTimeout);
    }

    public function testEnvironmentTimeoutIsBounded(): void
    {
        putenv('JOBRADAR_MCP_IDLE_TIMEOUT_SECONDS=900');
        self::assertSame(900, IdleStdioTransport::idleTimeoutFromEnvironment());

        putenv('JOBRADAR_MCP_IDLE_TIMEOUT_SECONDS=10');
        self::assertSame(1800, IdleStdioTransport::idleTimeoutFromEnvironment());

        putenv('JOBRADAR_MCP_IDLE_TIMEOUT_SECONDS=invalid');
        self::assertSame(1800, IdleStdioTransport::idleTimeoutFromEnvironment());
    }

    public function testOpenInputTerminatesAfterIdleDeadline(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $now = 0.0;

        $transport = new IdleStdioTransport(
            input: $pair[0],
            output: $output,
            idleTimeoutSeconds: 2,
            pollIntervalMicroseconds: 1000,
            clock: static function () use (&$now): float {
                return $now;
            },
            sleeper: static function () use (&$now): void {
                $now += 1.0;
            },
        );

        self::assertSame(0, $transport->listen());
        fclose($pair[1]);
        fclose($pair[0]);
        fclose($output);
    }
}
