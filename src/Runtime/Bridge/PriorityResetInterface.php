<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bridge;

use Symfony\Contracts\Service\ResetInterface;

interface PriorityResetInterface extends ResetInterface
{
    public function getPriority(): int;
}
