<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\ReadinessCheck;

enum ReadinessCheckStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
}
