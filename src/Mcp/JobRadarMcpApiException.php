<?php

declare(strict_types=1);

namespace App\Mcp;

final class JobRadarMcpApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $apiCode,
        public readonly int $httpStatus,
    ) {
        parent::__construct(sprintf('API JobRadaru odmítlo MCP operaci (%s, HTTP %d).', $apiCode, $httpStatus));
    }
}
