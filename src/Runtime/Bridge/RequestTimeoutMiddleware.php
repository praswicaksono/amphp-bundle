<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bridge;

use Amp\CancelledException;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;

use function Amp\async;

/**
 * Middleware that enforces a total timeout for the full request/response cycle.
 *
 * If the request handler (including Symfony kernel processing) takes longer than
 * the configured timeout, a 408 Request Timeout is returned. The handler fiber
 * continues running but its result is discarded.
 */
final class RequestTimeoutMiddleware implements Middleware
{
    public function __construct(
        private readonly int $timeout,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        if ($this->timeout <= 0) {
            return $requestHandler->handleRequest($request);
        }

        $handlerFuture = async(fn (): Response => $requestHandler->handleRequest($request));

        try {
            return $handlerFuture->await(new TimeoutCancellation($this->timeout));
        } catch (CancelledException) {
            $handlerFuture->ignore();

            return new Response(
                status: HttpStatus::REQUEST_TIMEOUT,
                headers: [
                    'content-type' => ['text/plain'],
                    'connection' => ['close'],
                ],
                body: '408 Request Timeout',
            );
        }
    }
}
