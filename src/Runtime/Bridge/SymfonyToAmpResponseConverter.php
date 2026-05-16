<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bridge;

use Amp\Http\Server\Response;
use PRSW\AmphpBundle\Bridge\Symfony\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class SymfonyToAmpResponseConverter
{
    public static function convert(SymfonyResponse $sfResponse): Response
    {
        $headers = [];
        foreach ($sfResponse->headers->all() as $name => $values) {
            if ('set-cookie' === $name) {
                continue;
            }

            foreach ($values as $value) {
                $headers[$name][] = $value;
            }
        }

        foreach ($sfResponse->headers->getCookies() as $cookie) {
            $headers['set-cookie'][] = $cookie->__toString();
        }

        if ($sfResponse instanceof StreamedResponse) {
            return self::convertAmpStreamed($sfResponse, $headers);
        }

        return new Response(
            status: $sfResponse->getStatusCode(),
            headers: $headers,
            body: $sfResponse->getContent() ?? '',
        );
    }

    private static function convertAmpStreamed(StreamedResponse $sfResponse, array $headers): Response
    {
        return new Response(
            status: $sfResponse->getStatusCode(),
            headers: $headers,
            body: new GeneratorResponseBody($sfResponse->getGenerator(...)),
        );
    }
}
