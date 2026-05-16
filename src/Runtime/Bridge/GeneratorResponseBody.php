<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bridge;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;

final class GeneratorResponseBody implements ReadableStream, \IteratorAggregate
{
    private ?\Generator $generator = null;
    private bool $started = false;
    private bool $closed = false;

    /** @var \Closure(): \Generator */
    private readonly \Closure $factory;

    /**
     * @param \Closure(): \Generator $factory Callable that returns a \Generator yielding string chunks
     */
    public function __construct(\Closure $factory)
    {
        $this->factory = $factory;
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }

        if (!$this->started) {
            $this->started = true;
            $this->generator = ($this->factory)();
        }

        if (!$this->generator->valid()) {
            $this->closed = true;
            $this->generator = null;

            return null;
        }

        $chunk = $this->generator->current();
        $this->generator->next();

        // Ensure we always return a string (or null if exhausted after next())
        if (!$this->generator->valid() && $chunk === null) {
            $this->closed = true;
            $this->generator = null;

            return null;
        }

        return $chunk;
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->generator !== null) {
            try {
                $this->generator->throw(new \Exception('Stream closed'));
            } catch (\Throwable) {
                // Generator may have already been completed or thrown
            }
            $this->generator = null;
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        // No external close notification mechanism needed
    }

    public function getIterator(): \Traversable
    {
        while (($chunk = $this->read()) !== null) {
            yield $chunk;
        }
    }
}
