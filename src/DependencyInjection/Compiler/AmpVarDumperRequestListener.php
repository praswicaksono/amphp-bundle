<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Bridge\Symfony\VarDumper\AmpDumpServerConnection;
use PRSW\AmphpBundle\Bridge\Symfony\VarDumper\AmpVarDumperHandler;

/**
 * @internal — Configures the AMPHP VarDumper handler on the first request.
 */
final class AmpVarDumperRequestListener
{
    public function __construct(
        private readonly ?AmpDumpServerConnection $connection = null,
    ) {}

    public function onKernelRequest(): void
    {
        AmpVarDumperHandler::register(connection: $this->connection);
    }
}
