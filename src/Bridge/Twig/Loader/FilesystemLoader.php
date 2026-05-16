<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Twig\Loader;

use Amp\File;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;
use Twig\Source;

final class FilesystemLoader extends TwigFilesystemLoader
{
    public function getSourceContext(string $name): Source
    {
        if (null === ($path = $this->findTemplate($name))) {
            return new Source('', $name, '');
        }

        $content = File\read($path);

        return new Source($content, $name, $path);
    }

    public function isFresh(string $name, int $time): bool
    {
        if (null === ($path = $this->findTemplate($name))) {
            return false;
        }

        $stat = File\getStatus($path);

        return $stat !== null && $stat['mtime'] < $time;
    }
}
