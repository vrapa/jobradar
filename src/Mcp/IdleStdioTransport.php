<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Transport\BaseTransport;
use Mcp\Server\Transport\Stdio\RunnerControl;
use Mcp\Server\Transport\Stdio\RunnerControlInterface;
use Mcp\Server\Transport\Stdio\RunnerState;
use Psr\Log\LoggerInterface;

/**
 * STDIO transport with a bounded idle lifetime.
 *
 * Codex can leave a disconnected MCP process with an open stdin pipe after a
 * task or model switch. The upstream transport consequently keeps polling
 * forever. This project-local transport preserves the SDK protocol behavior
 * while allowing an inactive process tree to terminate cleanly.
 *
 * @extends BaseTransport<int>
 */
final class IdleStdioTransport extends BaseTransport
{
    public const DEFAULT_MAX_LINE_BYTES = 4 * 1024 * 1024;
    public const DEFAULT_IDLE_TIMEOUT_SECONDS = 1800;
    public const DEFAULT_POLL_INTERVAL_MICROSECONDS = 250000;

    private bool $discardingLine = false;

    /** @var \Closure():float */
    private readonly \Closure $clock;

    /** @var \Closure(int):void */
    private readonly \Closure $sleeper;

    /**
     * @param resource $input
     * @param resource $output
     * @param callable():float|null $clock
     * @param callable(int):void|null $sleeper
     */
    public function __construct(
        private $input = \STDIN,
        private $output = \STDOUT,
        ?LoggerInterface $logger = null,
        private readonly RunnerControlInterface $runnerControl = new RunnerControl(),
        private readonly int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES,
        private readonly int $idleTimeoutSeconds = self::DEFAULT_IDLE_TIMEOUT_SECONDS,
        private readonly int $pollIntervalMicroseconds = self::DEFAULT_POLL_INTERVAL_MICROSECONDS,
        ?callable $clock = null,
        ?callable $sleeper = null,
    ) {
        parent::__construct($logger);

        if ($maxLineBytes < 1) {
            throw new InvalidArgumentException(sprintf('The maximum line size must be positive, got %d.', $maxLineBytes));
        }
        if ($idleTimeoutSeconds < 1) {
            throw new InvalidArgumentException(sprintf('The idle timeout must be positive, got %d.', $idleTimeoutSeconds));
        }
        if ($pollIntervalMicroseconds < 1000) {
            throw new InvalidArgumentException(sprintf('The poll interval must be at least 1000 microseconds, got %d.', $pollIntervalMicroseconds));
        }

        $this->clock = \Closure::fromCallable($clock ?? static fn (): float => microtime(true));
        $this->sleeper = \Closure::fromCallable($sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        });
    }

    public static function idleTimeoutFromEnvironment(): int
    {
        $raw = getenv('JOBRADAR_MCP_IDLE_TIMEOUT_SECONDS');
        if (!is_string($raw) || !ctype_digit($raw)) {
            return self::DEFAULT_IDLE_TIMEOUT_SECONDS;
        }

        $seconds = (int) $raw;

        return $seconds >= 60 && $seconds <= 86400
            ? $seconds
            : self::DEFAULT_IDLE_TIMEOUT_SECONDS;
    }

    public function send(string $data, array $context): void
    {
        if (isset($context['session_id'])) {
            $this->sessionId = $context['session_id'];
        }

        $this->writeLine($data);
    }

    public function listen(): int
    {
        $this->logger->info('IdleStdioTransport is listening for messages on STDIN.');
        stream_set_blocking($this->input, false);
        $lastInputAt = ($this->clock)();

        while (!feof($this->input) && RunnerState::RUNNING === $this->runnerControl->getState()) {
            if ($this->processInput()) {
                $lastInputAt = ($this->clock)();
            }

            $this->processFiber();
            $this->flushOutgoingMessages();

            if (($this->clock)() - $lastInputAt >= $this->idleTimeoutSeconds) {
                $this->logger->info('IdleStdioTransport reached its idle timeout.');
                break;
            }

            ($this->sleeper)($this->pollIntervalMicroseconds);
        }

        $this->logger->info('IdleStdioTransport finished listening.');
        if (in_array($this->runnerControl->getState(), [RunnerState::RUNNING, RunnerState::STOP_AND_END_SESSION], true)) {
            $this->handleSessionEnd($this->sessionId);
        }

        return 0;
    }

    private function processInput(): bool
    {
        $line = fgets($this->input, max(1, $this->maxLineBytes));
        if (false === $line) {
            return false;
        }

        $lineComplete = str_ends_with($line, "\n");
        if ($this->discardingLine) {
            $this->discardingLine = !$lineComplete;

            return true;
        }

        if (!$lineComplete && strlen($line) >= $this->maxLineBytes - 1) {
            $this->discardingLine = true;
            $this->logger->warning('IdleStdioTransport discarded an input line exceeding the maximum length.', [
                'max_line_bytes' => $this->maxLineBytes,
            ]);

            return true;
        }

        $trimmedLine = trim($line);
        if ('' !== $trimmedLine) {
            $this->handleMessage($trimmedLine, $this->sessionId);
        }

        return true;
    }

    private function processFiber(): void
    {
        if (null === $this->sessionFiber) {
            return;
        }
        if ($this->sessionFiber->isTerminated()) {
            $this->handleFiberTermination();

            return;
        }
        if (!$this->sessionFiber->isSuspended()) {
            return;
        }

        $pendingRequests = $this->getPendingRequests($this->sessionId);
        if ([] === $pendingRequests) {
            $yielded = $this->sessionFiber->resume();
            $this->handleFiberYield($yielded, $this->sessionId);

            return;
        }

        foreach ($pendingRequests as $pending) {
            $requestId = $pending['request_id'];
            $timestamp = $pending['timestamp'];
            $timeout = $pending['timeout'] ?? 120;
            $response = $this->checkForResponse($requestId, $this->sessionId);

            if (null !== $response) {
                $yielded = $this->sessionFiber->resume($response);
                $this->handleFiberYield($yielded, $this->sessionId);

                return;
            }
            if (time() - $timestamp >= $timeout) {
                $error = Error::forInternalError('Request timed out', $requestId);
                $yielded = $this->sessionFiber->resume($error);
                $this->handleFiberYield($yielded, $this->sessionId);

                return;
            }
        }
    }

    private function handleFiberTermination(): void
    {
        $finalResult = $this->sessionFiber?->getReturn();
        if (null !== $finalResult) {
            try {
                $this->writeLine(json_encode($finalResult, JSON_THROW_ON_ERROR));
            } catch (\JsonException $exception) {
                $this->logger->error('STDIO: Failed to encode final Fiber result.', ['exception' => $exception]);
            }
        }

        $this->sessionFiber = null;
    }

    private function flushOutgoingMessages(): void
    {
        foreach ($this->getOutgoingMessages($this->sessionId) as $message) {
            $this->writeLine($message['message']);
        }
    }

    private function writeLine(string $payload): void
    {
        fwrite($this->output, $payload . PHP_EOL);
        fflush($this->output);
    }

    public function close(): void
    {
        $this->handleSessionEnd($this->sessionId);
        if (is_resource($this->input)) {
            fclose($this->input);
        }
        if (is_resource($this->output)) {
            fclose($this->output);
        }
    }
}
