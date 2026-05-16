<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver;

use Doctrine\DBAL\Driver\AbstractException;

final class Exception extends AbstractException
{
    public function __construct(\Throwable $previous)
    {
        $sqlState = null;
        $message = $previous->getMessage();

        parent::__construct($message, $sqlState, (int) $previous->getCode(), $previous);
    }
}
