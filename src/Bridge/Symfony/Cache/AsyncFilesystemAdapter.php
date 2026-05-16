<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Cache;

use Amp\File\Filesystem;
use Amp\File\FilesystemException;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Exception\CacheException;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;
use Symfony\Component\Cache\PruneableInterface;

use function Amp\File\createDirectoryRecursively;
use function Amp\File\deleteFile;
use function Amp\File\isDirectory;
use function Amp\File\isFile;
use function Amp\File\listFiles;
use function Amp\File\read;
use function Amp\File\write;

final class AsyncFilesystemAdapter extends AbstractAdapter implements PruneableInterface
{
    private string $directory;
    private MarshallerInterface $marshaller;

    public function __construct(
        string $namespace = '',
        int $defaultLifetime = 0,
        ?string $directory = null,
        ?MarshallerInterface $marshaller = null,
    ) {
        $this->marshaller = $marshaller ?? new DefaultMarshaller();
        parent::__construct('', $defaultLifetime);

        if (!isset($directory[0])) {
            $directory = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'symfony-cache';
        } else {
            $directory = \realpath($directory) ?: $directory;
        }
        if (isset($namespace[0])) {
            if (\preg_match('#[^-+_.A-Za-z0-9]#', $namespace, $match)) {
                throw new InvalidArgumentException(\sprintf(
                    'Namespace contains "%s" but only characters in [-+_.A-Za-z0-9] are allowed.',
                    $match[0],
                ));
            }
            $directory .= \DIRECTORY_SEPARATOR . $namespace;
        } else {
            $directory .= \DIRECTORY_SEPARATOR . '@';
        }
        $directory .= \DIRECTORY_SEPARATOR;

        // On Windows the whole path is limited to 258 chars
        if ('\\' === \DIRECTORY_SEPARATOR && \strlen($directory) > 234) {
            throw new InvalidArgumentException(\sprintf('Cache directory too long (%s).', $directory));
        }

        $this->directory = $directory;
    }

    protected function doFetch(array $ids): iterable
    {
        $values = [];
        $now = \time();

        foreach ($ids as $id) {
            $file = $this->getFile($id);

            try {
                if (!isFile($file)) {
                    continue;
                }
            } catch (FilesystemException) {
                continue;
            }

            try {
                $content = read($file);
            } catch (FilesystemException) {
                continue;
            }

            // Parse 3-line format: expiresAt \n rawurlencoded_key \n serialized_value
            $lfPos = \strpos($content, "\n");
            if (false === $lfPos) {
                continue;
            }

            $expiresAt = (int) \substr($content, 0, $lfPos);
            if ($expiresAt && $now >= $expiresAt) {
                // Expired — delete asynchronously (fire-and-forget)
                try {
                    deleteFile($file);
                } catch (FilesystemException) {
                    // Ignore deletion errors
                }
                continue;
            }

            $rest = \substr($content, $lfPos + 1);
            $lfPos2 = \strpos($rest, "\n");
            if (false === $lfPos2) {
                continue;
            }

            $storedKey = \rawurldecode(\rtrim(\substr($rest, 0, $lfPos2)));
            $value = \substr($rest, $lfPos2 + 1);

            if ($storedKey === $id) {
                $values[$id] = $this->marshaller->unmarshall($value);
            }
        }

        return $values;
    }

    protected function doHave(string $id): bool
    {
        $file = $this->getFile($id);

        try {
            if (!isFile($file)) {
                return false;
            }
        } catch (FilesystemException) {
            return false;
        }

        // Quick check: if mtime is in the future, the item is still valid
        try {
            $status = new Filesystem(\Amp\File\createDefaultDriver())->getStatus($file);
        } catch (FilesystemException) {
            $status = null;
        }

        if (null !== $status && ($status['mtime'] ?? 0) > \time()) {
            return true;
        }

        // Fall back to doFetch which checks expiry properly
        foreach ($this->doFetch([$id]) as $_) {
            return true;
        }

        return false;
    }

    protected function doClear(string $namespace): bool
    {
        $ok = true;

        /** @var string $file */
        foreach ($this->scanHashDir($this->directory) as $file) {
            if ('' !== $namespace) {
                $key = $this->getFileKey($file);
                if ('' === $key || !\str_starts_with($key, $namespace)) {
                    continue;
                }
            }

            try {
                deleteFile($file);
            } catch (FilesystemException) {
                // If the file no longer exists, that's OK
                if (isFile($file)) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    protected function doDelete(array $ids): bool
    {
        $ok = true;

        foreach ($ids as $id) {
            $file = $this->getFile($id);

            try {
                if (!isFile($file)) {
                    continue;
                }
                deleteFile($file);
            } catch (FilesystemException) {
                if (isFile($file)) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    protected function doSave(array $values, int $lifetime): array|bool
    {
        $expiresAt = $lifetime ? \time() + $lifetime : 0;
        $values = $this->marshaller->marshall($values, $failed);

        foreach ($values as $id => $value) {
            $file = $this->getFile($id);

            // Ensure the hash subdirectory exists
            $dir = \dirname($file);
            try {
                if (!isDirectory($dir)) {
                    createDirectoryRecursively($dir, 0o777);
                }
            } catch (FilesystemException) {
                $failed[] = $id;
                continue;
            }

            $content = $expiresAt . "\n" . \rawurlencode($id) . "\n" . $value;

            try {
                write($file, $content);
            } catch (FilesystemException) {
                $failed[] = $id;
                continue;
            }

            // Set mtime to expiry for fast doHave checks (fire-and-forget)
            if (null !== $expiresAt) {
                try {
                    new Filesystem(\Amp\File\createDefaultDriver())->touch($file, $expiresAt ?: \time() + 31_556_952);
                } catch (FilesystemException) {
                    // Non-critical
                }
            }
        }

        if ($failed && !is_writable($this->directory)) {
            throw new CacheException(\sprintf('Cache directory is not writable (%s).', $this->directory));
        }

        return $failed;
    }

    public function prune(): bool
    {
        $time = \time();
        $pruned = true;

        foreach ($this->scanHashDir($this->directory) as $file) {
            try {
                $content = read($file);
            } catch (FilesystemException) {
                continue;
            }

            $lfPos = \strpos($content, "\n");
            if (false === $lfPos) {
                continue;
            }

            $expiresAt = (int) \substr($content, 0, $lfPos);

            if ($expiresAt && $time >= $expiresAt) {
                try {
                    deleteFile($file);
                } catch (FilesystemException) {
                    $pruned = false;
                }
            }
        }

        return $pruned;
    }

    private function getFile(string $id): string
    {
        $hash = \str_replace('/', '-', \base64_encode(\hash('xxh128', static::class . $id, true)));
        $dir =
            $this->directory
            . \strtoupper($hash[0])
            . \DIRECTORY_SEPARATOR
            . \strtoupper($hash[1])
            . \DIRECTORY_SEPARATOR;

        return $dir . \substr($hash, 2, 20);
    }

    private function getFileKey(string $file): string
    {
        try {
            $content = read($file);
        } catch (FilesystemException) {
            return '';
        }

        $lfPos = \strpos($content, "\n");
        if (false === $lfPos) {
            return '';
        }

        $rest = \substr($content, $lfPos + 1);
        $lfPos2 = \strpos($rest, "\n");
        if (false === $lfPos2) {
            return '';
        }

        return \rawurldecode(\rtrim(\substr($rest, 0, $lfPos2)));
    }

    private function scanHashDir(string $directory): \Generator
    {
        try {
            if (!isDirectory($directory)) {
                return;
            }
        } catch (FilesystemException) {
            return;
        }

        $chars = '+-ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        for ($i = 0; $i < 38; ++$i) {
            $level1Dir = $directory . $chars[$i];
            try {
                if (!isDirectory($level1Dir)) {
                    continue;
                }
            } catch (FilesystemException) {
                continue;
            }

            for ($j = 0; $j < 38; ++$j) {
                $level2Dir = $level1Dir . \DIRECTORY_SEPARATOR . $chars[$j];
                try {
                    if (!isDirectory($level2Dir)) {
                        continue;
                    }
                } catch (FilesystemException) {
                    continue;
                }

                try {
                    $files = listFiles($level2Dir);
                } catch (FilesystemException) {
                    continue;
                }

                foreach ($files as $file) {
                    if (!('.' !== $file && '..' !== $file)) {
                        continue;
                    }

                    yield $level2Dir . \DIRECTORY_SEPARATOR . $file;
                }
            }
        }
    }
}
