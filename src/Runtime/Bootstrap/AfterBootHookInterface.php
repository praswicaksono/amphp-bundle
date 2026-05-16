<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bootstrap;

use Psr\Container\ContainerInterface;

interface AfterBootHookInterface
{
    public function onAfterBoot(ContainerInterface $container): void;
}
