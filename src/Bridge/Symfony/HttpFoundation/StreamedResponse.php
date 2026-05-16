<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\HttpFoundation;

use Symfony\Component\HttpFoundation\Response;

class StreamedResponse extends Response
{
    public function __construct(
        private readonly \Closure $generatorFactory,
        int $status = 200,
        array $headers = [],
    ) {
        parent::__construct('', $status, $headers);
    }

    public function getGenerator(): \Generator
    {
        /** @var \Generator<string> */
        return ($this->generatorFactory)();
    }
}
