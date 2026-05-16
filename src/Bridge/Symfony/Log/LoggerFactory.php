<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Log;

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Handler\PsrHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    public static function create(
        string $name = 'app',
        string $output = 'php://stdout',
        ?LoggerInterface $fallbackLogger = null,
    ): LoggerInterface {
        // Cluster worker: route logs through the cluster IPC so they appear
        // in the watcher's centralised output (see Cluster::createLogHandler).
        if (Cluster::isWorker()) {
            $name = 'worker.' . \getmypid();

            $level =
                (\getenv('APP_DEBUG') ?? '0') === '1' || (\getenv('APP_DEBUG') ?? '0') === 'true'
                    ? Level::Debug
                    : Level::Info;
            $handler = Cluster::createLogHandler($level);

            $logger = new Logger($name);
            $logger->pushHandler($handler);

            return $logger;
        }

        // Standalone AMPHP server (e.g. php public/index.php): log to stdout
        // via the non-blocking AMPHP stream handler.
        if (\defined('AMPHP_WORKER')) {
            $level =
                (\getenv('APP_DEBUG') ?? '0') === '1' || (\getenv('APP_DEBUG') ?? '0') === 'true'
                    ? Level::Debug
                    : Level::Info;

            $stream = self::resolveStream($output);
            $handler = new StreamHandler($stream, $level);
            $handler->setFormatter(new ConsoleFormatter());

            $logger = new Logger($name);
            $logger->pushHandler($handler);

            return $logger;
        }

        // Regular CLI (e.g. bin/console): wrap in a Monolog logger so that
        // pushProcessor() calls from the compiled container (added by
        // LogIntegrationPass) always succeed. The Symfony Logger does not
        // implement pushProcessor().
        $inner = $fallbackLogger ?? self::createDefaultLogger($name, $output);

        // If it already supports pushProcessor (Monolog Logger, Symfony
        // Monolog bridge, etc.), return it directly.
        if (\method_exists($inner, 'pushProcessor')) {
            return $inner;
        }

        // Symfony HttpKernel Logger: wrap in Monolog via PsrHandler.
        $monolog = new Logger($name);
        $monolog->pushHandler(new PsrHandler($inner));

        return $monolog;
    }

    private static function createDefaultLogger(string $name, string $output): LoggerInterface
    {
        $level =
            (\getenv('APP_DEBUG') ?? '0') === '1' || (\getenv('APP_DEBUG') ?? '0') === 'true'
                ? Level::Debug
                : Level::Info;

        $stream = self::resolveStream($output);
        $handler = new StreamHandler($stream, $level);
        $handler->setFormatter(new ConsoleFormatter());

        $logger = new Logger($name);
        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * Resolve an output string to an Amp\ByteStream\WritableStream.
     */
    private static function resolveStream(string $output): ByteStream\WritableStream
    {
        return match ($output) {
            'php://stdout' => ByteStream\getStdout(),
            'php://stderr' => ByteStream\getStderr(),
            default => throw new \InvalidArgumentException(\sprintf(
                'Unsupported output target "%s". Use "php://stdout" or "php://stderr".',
                $output,
            )),
        };
    }
}
