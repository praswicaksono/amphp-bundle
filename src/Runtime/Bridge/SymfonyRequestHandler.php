<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bridge;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use PRSW\AmphpBundle\Bridge\Symfony\HttpFoundation\SseResponse;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

final class SymfonyRequestHandler implements RequestHandler
{
    private int $requestCount = 0;
    private bool $drainRequested = false;

    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly LoggerInterface $logger,
        private readonly ?ErrorRendererInterface $errorRenderer = null,
        private readonly ?int $maxRequests = null,
        private readonly ?\Closure $onShutdownRequested = null,
        private readonly float $shutdownTimeout = 5.0,
        private readonly ?RequestResetter $resetter = null,
        private readonly ?int $maxBodySize = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        DatabaseResetter::captureFiber(\Fiber::getCurrent());

        // When drain is active, reject new requests immediately with 503
        if ($this->drainRequested) {
            return new Response(
                status: 503,
                headers: [
                    'content-type' => ['text/plain'],
                    'retry-after' => [(string) (int) $this->shutdownTimeout],
                ],
                body: '503 Service Unavailable',
            );
        }

        try {
            $sfRequest = AmpToSymfonyRequestConverter::convert($request, $this->maxBodySize);
            $sfResponse = $this->kernel->handle($sfRequest);

            if ($sfResponse instanceof SseResponse) {
                $sfResponse->setAmpRequest($request);
            }

            $return = SymfonyToAmpResponseConverter::convert($sfResponse);

            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($sfRequest, $sfResponse);
            }

            $this->maybeRequestShutdown();
        } catch (BodySizeExceededException $e) {
            $this->logger->warning('Request body exceeded maximum size: {actual} > {max}', [
                'actual' => $e->actualSize,
                'max' => $e->maxSize,
            ]);

            $return = new Response(
                status: 413,
                headers: [
                    'content-type' => ['text/plain'],
                    'connection' => ['close'],
                ],
                body: '413 Payload Too Large',
            );
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled exception: {exception}', [
                'exception' => $e,
            ]);

            try {
                $renderer = $this->errorRenderer;

                if ($renderer === null) {
                    $debug = $this->kernel instanceof KernelInterface
                        ? $this->kernel->isDebug()
                        : false;
                    $renderer = new HtmlErrorRenderer(debug: $debug);
                }

                $flattenException = $renderer->render($e);

                $return = new Response(
                    status: $flattenException->getStatusCode(),
                    headers: $flattenException->getHeaders(),
                    body: $flattenException->getAsString(),
                );
            } catch (\Throwable $renderException) {
                $this->logger->error('Failed to render error page: {exception}', [
                    'exception' => $renderException,
                ]);

                $return = new Response(
                    status: 500,
                    headers: ['content-type' => ['text/plain']],
                    body: '500 Internal Server Error',
                );
            }
        } finally {
            // Run all registered resetters in priority order:
            //   1. DatabaseResetter    (100) — release pooled connection
            //   2. User resetters       (any) — user-defined cleanup
            //   3. DebugLoggerResetter (-255) — clear debug logs
            $this->resetter?->reset();
        }

        return $return;
    }

    private function maybeRequestShutdown(): void
    {
        if ($this->maxRequests === null) {
            return;
        }

        ++$this->requestCount;

        if ($this->requestCount >= $this->maxRequests) {
            $this->drainRequested = true;

            EventLoop::delay($this->shutdownTimeout, fn() => ($this->onShutdownRequested)());
        }
    }
}
