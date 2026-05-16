<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Log;

use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;

final class DebugLogger implements DebugLoggerInterface
{
    /** @var array<string|int, list<array<string, mixed>>> */
    private array $logs = [];

    /** @var array<string|int, int> */
    private array $errorCount = [];

    /**
     * @var array<string, int> Map of PSR-3 log level to Symfony priority
     * @see \Symfony\Component\HttpKernel\Log\Logger::PRIORITIES
     */
    private const array PRIORITIES = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    public function __construct(
        private readonly ?RequestStack $requestStack = null,
    ) {}

    /**
     * Monolog processor: capture the log record for the profiler.
     *
     * @return LogRecord The unmodified record (pass-through)
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack?->getCurrentRequest();
        $key = $request ? \spl_object_id($request) : '';

        $levelName = $record->level->toPsrLogLevel();

        $this->logs[$key][] = [
            'channel' => $record->channel,
            'context' => $record->context,
            'message' => $record->message,
            'priority' => self::PRIORITIES[$levelName] ?? 0,
            'priorityName' => $levelName,
            'timestamp' => $record->datetime->getTimestamp(),
            'timestamp_rfc3339' => $record->datetime->format(\DATE_RFC3339_EXTENDED),
        ];

        if (!isset($this->errorCount[$key])) {
            $this->errorCount[$key] = 0;
        }

        if ($record->level->value >= 400) {
            ++$this->errorCount[$key];
        }

        return $record;
    }

    public function getLogs(?Request $request = null): array
    {
        if ($request) {
            return $this->logs[\spl_object_id($request)] ?? [];
        }

        if ($this->logs === []) {
            return [];
        }

        return \array_merge(...\array_values($this->logs));
    }

    public function countErrors(?Request $request = null): int
    {
        if ($request) {
            return $this->errorCount[\spl_object_id($request)] ?? 0;
        }

        return \array_sum($this->errorCount);
    }

    public function clear(): void
    {
        $this->logs = [];
        $this->errorCount = [];
    }
}
