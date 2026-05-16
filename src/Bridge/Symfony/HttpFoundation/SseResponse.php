<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\HttpFoundation;

use Amp\Http\Server\Request;

class SseResponse extends StreamedResponse
{
    private ?Request $ampRequest = null;

    /**
     * @param \Closure(): \Generator<int, SseEvent> $generatorFactory
     */
    public function __construct(
        \Closure $generatorFactory,
        int $status = 200,
        array $headers = [],
    ) {
        parent::__construct($generatorFactory, $status, $headers);

        $this->headers->set('Content-Type', 'text/event-stream');
        $this->headers->set('Cache-Control', 'no-cache');
        $this->headers->set('Connection', 'keep-alive');
        $this->headers->set('X-Accel-Buffering', 'no');
    }

    public function setAmpRequest(Request $request): void
    {
        $this->ampRequest = $request;
    }

    public function getGenerator(): \Generator
    {
        $inner = parent::getGenerator();
        $ampRequest = $this->ampRequest;

        $wrapper = function () use ($inner, $ampRequest): \Generator {
            try {
                /** @var SseEvent $event */
                foreach ($inner as $event) {
                    if (!$event instanceof SseEvent) {
                        throw new \InvalidArgumentException(\sprintf(
                            'SseResponse generator must only yield SseEvent objects, got "%s".',
                            $event::class,
                        ));
                    }

                    // Proactively check if the client has disconnected
                    if ($ampRequest !== null && $ampRequest->getClient()->isClosed()) {
                        break;
                    }

                    yield $this->formatEvent($event);
                }
            } catch (\Throwable) {
                // Generator cancelled (client disconnect, stream closed, etc.)
            }
        };

        return $wrapper();
    }

    private function formatEvent(SseEvent $event): string
    {
        $output = '';

        if ($event->id !== null) {
            $output .= "id: {$event->id}\n";
        }

        if ($event->event !== null) {
            $output .= "event: {$event->event}\n";
        }

        if ($event->retry !== null) {
            $output .= "retry: {$event->retry}\n";
        }

        $data = \json_encode($event->data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        // SSE spec: each line of multi-line data must be prefixed with "data: "
        foreach (\explode("\n", $data) as $line) {
            $output .= "data: {$line}\n";
        }

        return $output . "\n"; // blank line terminates the event per SSE spec
    }
}
