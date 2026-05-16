<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Amphp;

use Amp\ByteStream\BufferedReader;
use Amp\Process\Process;
use Revolt\EventLoop;

final class FsWatchWatcher
{
    private const DEBOUNCE_SECONDS = 0.5;

    private ?Process $process = null;
    private bool $running = false;
    private ?string $debounceWatcherId = null;
    private bool $restarting = false;

    /**
     * @param list<string>     $directories Absolute paths to watch
     * @param \Closure(): void $onChange    Called when a watched file changes
     * @param list<string>     $extensions  File extensions to watch for
     */
    public function __construct(
        private readonly array $directories,
        private readonly \Closure $onChange,
        private readonly array $extensions = ['php', 'twig', 'yaml', 'yml', 'xml'],
    ) {}

    /**
     * Start the fswatch process and begin monitoring file changes.
     *
     * @throws \RuntimeException If fswatch is not installed or the process fails to start
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $command = \array_merge(
            ['fswatch', '-r', '--event=Created', '--event=Updated', '--event=Removed'],
            $this->directories,
        );

        $this->process = Process::start($command);

        if (!$this->process->isRunning()) {
            throw new \RuntimeException(\sprintf(
                'Failed to start fswatch: %s',
                $this->process->getCommand(),
            ));
        }

        $this->running = true;

        $reader = new BufferedReader($this->process->getStdout());

        EventLoop::queue(function () use ($reader): void {
            try {
                while ($this->running) {
                    $line = \trim($reader->readUntil("\n"));

                    if ($line === '' || !$this->isWatchable($line)) {
                        continue;
                    }

                    // If a restart is already in progress, discard this
                    // event — the new worker already reflects the latest
                    // filesystem state.
                    if ($this->restarting) {
                        continue;
                    }

                    // Resettable debounce: each new event resets the timer.
                    // onChange only fires after DEBOUNCE_SECONDS of silence,
                    // coalescing multiple events from a single editor save.
                    if ($this->debounceWatcherId !== null) {
                        EventLoop::cancel($this->debounceWatcherId);
                    }

                    $this->debounceWatcherId = EventLoop::delay(
                        self::DEBOUNCE_SECONDS,
                        function (): void {
                            $this->debounceWatcherId = null;
                            $this->restarting = true;

                            try {
                                ($this->onChange)();
                            } finally {
                                $this->restarting = false;
                            }
                        },
                    );
                }
            } catch (\Throwable) {
                // Process exited or was stopped — watcher will be cleaned up
            }
        });
    }

    /**
     * Stop the fswatch process.
     */
    public function stop(): void
    {
        $this->running = false;

        if ($this->debounceWatcherId !== null) {
            EventLoop::cancel($this->debounceWatcherId);
            $this->debounceWatcherId = null;
        }

        if ($this->process !== null && $this->process->isRunning()) {
            $this->process->signal(\SIGTERM);
        }
    }

    /**
     * Check if a file path matches one of the watched extensions.
     */
    private function isWatchable(string $path): bool
    {
        return \in_array(
            \strtolower(\pathinfo($path, \PATHINFO_EXTENSION)),
            $this->extensions,
            true,
        );
    }
}
