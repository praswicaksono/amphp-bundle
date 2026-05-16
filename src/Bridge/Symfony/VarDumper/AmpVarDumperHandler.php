<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\VarDumper;

use Symfony\Component\VarDumper\Cloner\ClonerInterface;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\DataDumperInterface;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Symfony\Component\VarDumper\VarDumper;

final class AmpVarDumperHandler
{
    public static function register(
        ?ClonerInterface $cloner = null,
        ?DataDumperInterface $dumper = null,
        ?AmpDumpServerConnection $connection = null,
    ): void {
        // Only register once
        if (self::isRegistered()) {
            return;
        }

        $cloner ??= new VarCloner();

        VarDumper::setHandler(static function (mixed $var, ?string $label = null) use ($cloner, $connection): ?string {
            $data = $cloner->cloneVar($var);
            if (null !== $label) {
                $data = $data->withContext(['label' => $label]);
            }

            // Try remote dump server first (non-blocking via amphp/socket)
            if ($connection && $connection->write($data)) {
                return null;
            }

            $dumper = defined('AMPHP_WORKER') ? new HtmlDumper() : new CliDumper();
            return $dumper->dump($data);
        });
    }

    public static function isRegistered(): bool
    {
        $handler = VarDumper::setHandler(static fn() => null);
        VarDumper::setHandler($handler);

        return $handler !== null;
    }
}
