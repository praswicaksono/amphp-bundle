<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Server;

interface ServerRunnerInterface
{
    public function run(): int;

    public function getServerConfig(): ServerConfig;
}
