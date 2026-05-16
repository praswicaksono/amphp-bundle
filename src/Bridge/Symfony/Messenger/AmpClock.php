<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Messenger;

use Symfony\Component\Clock\ClockInterface;

final class AmpClock implements ClockInterface
{
    public function __construct(
        private ClockInterface $inner,
    ) {}

    public function sleep(float|int $seconds): void
    {
        // Convert seconds to milliseconds and use Amp's non-blocking delay.
        // This suspends the current fiber instead of blocking the event loop.
        \Amp\delay((int) ($seconds * 1000));
    }

    public function now(): \Symfony\Component\Clock\DatePoint
    {
        return $this->inner->now();
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withTimeZone($timezone);

        return $clone;
    }
}
