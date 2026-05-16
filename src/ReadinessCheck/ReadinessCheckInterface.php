<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\ReadinessCheck;

interface ReadinessCheckInterface
{
    public function check(): ReadinessCheckResult;
}
