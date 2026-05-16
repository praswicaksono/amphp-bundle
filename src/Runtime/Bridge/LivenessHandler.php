<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bridge;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

final readonly class LivenessHandler implements RequestHandler
{
    public function handleRequest(Request $request): Response
    {
        return new Response(
            status: HttpStatus::OK,
            headers: ['content-type' => 'application/json'],
            body: \json_encode(
                ['status' => 'alive'],
                \JSON_THROW_ON_ERROR,
            ),
        );
    }
}
