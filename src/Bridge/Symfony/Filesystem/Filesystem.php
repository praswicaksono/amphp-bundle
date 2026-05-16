<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Filesystem;

use Amp\File;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Filesystem\Path;

final class Filesystem extends SymfonyFilesystem
{
    private const int MAX_PATH_LENGTH = \PHP_MAXPATHLEN - 2;

    public function copy(string $originFile, string $targetFile, bool $overwriteNewerFiles = false): void
    {
        $originIsLocal = stream_is_local($originFile) || 0 === stripos($originFile, 'file://');

        if ($originIsLocal && !File\exists($originFile)) {
            throw new FileNotFoundException(
                \sprintf('Failed to copy "%s" because file does not exist.', $originFile),
                0,
                null,
                $originFile,
            );
        }

        $this->mkdir(\dirname($targetFile));

        $doCopy = true;
        if (!$overwriteNewerFiles && !parse_url($originFile, \PHP_URL_HOST) && File\exists($targetFile)) {
            $originMtime = File\getModificationTime($originFile);
            $targetMtime = File\getModificationTime($targetFile);
            $doCopy = $originMtime > $targetMtime;
        }

        if ($doCopy) {
            try {
                $content = File\read($originFile);
            } catch (File\FilesystemException $e) {
                throw new IOException(
                    \sprintf(
                        'Failed to copy "%s" to "%s" because source file could not be read: ',
                        $originFile,
                        $targetFile,
                    )
                        . $e->getMessage(),
                    0,
                    $e,
                    $originFile,
                );
            }

            try {
                File\write($targetFile, $content);
            } catch (File\FilesystemException $e) {
                throw new IOException(
                    \sprintf(
                        'Failed to copy "%s" to "%s" because target file could not be written: ',
                        $originFile,
                        $targetFile,
                    )
                        . $e->getMessage(),
                    0,
                    $e,
                    $originFile,
                );
            }

            if (!File\exists($targetFile)) {
                throw new IOException(
                    \sprintf('Failed to copy "%s" to "%s".', $originFile, $targetFile),
                    0,
                    null,
                    $originFile,
                );
            }

            if ($originIsLocal) {
                // Preserve executable permission bits (like `cp`)
                try {
                    $originPerms = File\getStatus($originFile)['mode'] ?? null;
                    $targetPerms = File\getStatus($targetFile)['mode'] ?? null;
                    if ($originPerms !== null && $targetPerms !== null) {
                        $newPerms = $targetPerms | ($originPerms & 0o111);
                        File\changePermissions($targetFile, $newPerms & 0o777);
                    }
                } catch (File\FilesystemException) {
                    // Best-effort
                }

                // Preserve modification time
                try {
                    $originMtime = File\getModificationTime($originFile);
                    File\touch($targetFile, $originMtime);
                } catch (File\FilesystemException) {
                    // Best-effort
                }

                // Verify size
                try {
                    $bytesCopied = \strlen($content);
                    $bytesOrigin = File\getSize($originFile);
                    if ($bytesCopied !== $bytesOrigin) {
                        throw new IOException(
                            \sprintf(
                                'Failed to copy the whole content of "%s" to "%s" (%g of %g bytes copied).',
                                $originFile,
                                $targetFile,
                                $bytesCopied,
                                $bytesOrigin,
                            ),
                            0,
                            null,
                            $originFile,
                        );
                    }
                } catch (File\FilesystemException $e) {
                    if ($e instanceof IOException) {
                        throw $e;
                    }
                }
            }
        }
    }

    public function mkdir(string|iterable $dirs, int $mode = 0o777): void
    {
        foreach ($this->toIterable($dirs) as $dir) {
            if (File\isDirectory($dir)) {
                continue;
            }

            try {
                File\createDirectoryRecursively($dir, $mode);
            } catch (File\FilesystemException $e) {
                if (!File\isDirectory($dir)) {
                    throw new IOException(\sprintf('Failed to create "%s": ', $dir) . $e->getMessage(), 0, $e, $dir);
                }
            }
        }
    }

    public function exists(string|iterable $files): bool
    {
        foreach ($this->toIterable($files) as $file) {
            if (\strlen($file) > self::MAX_PATH_LENGTH) {
                throw new IOException(
                    \sprintf(
                        'Could not check if file exist because path length exceeds %d characters.',
                        self::MAX_PATH_LENGTH,
                    ),
                    0,
                    null,
                    $file,
                );
            }

            if (!File\exists($file)) {
                return false;
            }
        }

        return true;
    }

    public function touch(string|iterable $files, ?int $time = null, ?int $atime = null): void
    {
        foreach ($this->toIterable($files) as $file) {
            try {
                File\touch($file, $time, $atime);
            } catch (File\FilesystemException $e) {
                throw new IOException(\sprintf('Failed to touch "%s": ', $file) . $e->getMessage(), 0, $e, $file);
            }
        }
    }

    public function remove(string|iterable $files): void
    {
        if ($files instanceof \Traversable) {
            $files = \iterator_to_array($files, false);
        } elseif (!\is_array($files)) {
            $files = [$files];
        }

        $this->doRemove($files);
    }

    private function doRemove(array $files, bool $isRecursive = false): void
    {
        $files = \array_reverse($files);
        foreach ($files as $file) {
            if (File\isSymlink($file)) {
                try {
                    File\deleteFile($file);
                } catch (File\FilesystemException $e) {
                    if (File\exists($file)) {
                        throw new IOException(
                            \sprintf('Failed to remove symlink "%s": ', $file) . $e->getMessage(),
                            0,
                            $e,
                        );
                    }
                }
            } elseif (File\isDirectory($file)) {
                if (!$isRecursive) {
                    // On non-recursive delete of a directory, we still need to
                    // remove its contents first (like the parent does).
                }

                try {
                    $children = File\listFiles($file);
                    if ($children !== []) {
                        $paths = \array_map(static fn(string $child) => $file . '/' . \basename($child), $children);
                        $this->doRemove($paths, true);
                    }

                    File\deleteDirectory($file);
                } catch (File\FilesystemException $e) {
                    if (File\exists($file)) {
                        throw new IOException(
                            \sprintf('Failed to remove directory "%s": ', $file) . $e->getMessage(),
                            0,
                            $e,
                        );
                    }
                }
            } elseif (File\exists($file)) {
                try {
                    File\deleteFile($file);
                } catch (File\FilesystemException $e) {
                    if (File\exists($file)) {
                        throw new IOException(
                            \sprintf('Failed to remove file "%s": ', $file) . $e->getMessage(),
                            0,
                            $e,
                        );
                    }
                }
            }
        }
    }

    public function chmod(string|iterable $files, int $mode, int $umask = 0o000, bool $recursive = false): void
    {
        foreach ($this->toIterable($files) as $file) {
            try {
                File\changePermissions($file, $mode & ~$umask);
            } catch (File\FilesystemException $e) {
                throw new IOException(\sprintf('Failed to chmod file "%s": ', $file) . $e->getMessage(), 0, $e, $file);
            }

            if ($recursive && File\isDirectory($file) && !File\isSymlink($file)) {
                $children = File\listFiles($file);
                if ($children !== []) {
                    $paths = \array_map(static fn(string $child) => $file . '/' . \basename($child), $children);
                    $this->chmod($paths, $mode, $umask, true);
                }
            }
        }
    }

    public function chown(string|iterable $files, string|int $user, bool $recursive = false): void
    {
        foreach ($this->toIterable($files) as $file) {
            if ($recursive && File\isDirectory($file) && !File\isSymlink($file)) {
                $children = File\listFiles($file);
                if ($children !== []) {
                    $paths = \array_map(static fn(string $child) => $file . '/' . \basename($child), $children);
                    $this->chown($paths, $user, true);
                }
            }

            try {
                File\changeOwner($file, $user, null);
            } catch (File\FilesystemException $e) {
                throw new IOException(\sprintf('Failed to chown file "%s": ', $file) . $e->getMessage(), 0, $e, $file);
            }
        }
    }

    public function chgrp(string|iterable $files, string|int $group, bool $recursive = false): void
    {
        foreach ($this->toIterable($files) as $file) {
            if ($recursive && File\isDirectory($file) && !File\isSymlink($file)) {
                $children = File\listFiles($file);
                if ($children !== []) {
                    $paths = \array_map(static fn(string $child) => $file . '/' . \basename($child), $children);
                    $this->chgrp($paths, $group, true);
                }
            }

            try {
                File\changeOwner($file, null, $group);
            } catch (File\FilesystemException $e) {
                throw new IOException(\sprintf('Failed to chgrp file "%s": ', $file) . $e->getMessage(), 0, $e, $file);
            }
        }
    }

    public function rename(string $origin, string $target, bool $overwrite = false): void
    {
        if (!$overwrite && File\exists($target)) {
            throw new IOException(
                \sprintf('Cannot rename because the target "%s" already exists.', $target),
                0,
                null,
                $target,
            );
        }

        try {
            File\move($origin, $target);
        } catch (File\FilesystemException) {
            if (File\isDirectory($origin)) {
                // Some filesystems don't support rename across mount points.
                // Fall back to mirror + remove.
                $this->mirror($origin, $target, null, ['override' => $overwrite, 'delete' => $overwrite]);
                $this->remove($origin);

                return;
            }

            throw new IOException(\sprintf('Cannot rename "%s" to "%s".', $origin, $target), 0, null, $target);
        }
    }

    public function symlink(string $originDir, string $targetDir, bool $copyOnWindows = false): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            $originDir = \strtr($originDir, '/', '\\');
            $targetDir = \strtr($targetDir, '/', '\\');

            if ($copyOnWindows) {
                $this->mirror($originDir, $targetDir);

                return;
            }
        }

        $this->mkdir(\dirname($targetDir));

        if (File\isSymlink($targetDir)) {
            $existingTarget = File\resolveSymlink($targetDir);
            if ($existingTarget === $originDir) {
                return;
            }
            $this->remove($targetDir);
        }

        try {
            File\createSymlink($originDir, $targetDir);
        } catch (File\FilesystemException $e) {
            throw new IOException(
                \sprintf('Failed to create symbolic link from "%s" to "%s": ', $originDir, $targetDir)
                    . $e->getMessage(),
                0,
                $e,
                $targetDir,
            );
        }
    }

    public function hardlink(string $originFile, string|iterable $targetFiles): void
    {
        if (!File\exists($originFile)) {
            throw new FileNotFoundException(null, 0, null, $originFile);
        }

        if (!File\isFile($originFile)) {
            throw new FileNotFoundException(\sprintf('Origin file "%s" is not a file.', $originFile));
        }

        foreach ($this->toIterable($targetFiles) as $targetFile) {
            if (File\isFile($targetFile)) {
                // If the target already points to the same inode, skip.
                // Amp\File doesn't expose inode comparison, so we just remove and re-link.
                $this->remove($targetFile);
            }

            try {
                File\createHardlink($originFile, $targetFile);
            } catch (File\FilesystemException $e) {
                throw new IOException(
                    \sprintf('Failed to create hard link from "%s" to "%s": ', $originFile, $targetFile)
                        . $e->getMessage(),
                    0,
                    $e,
                    $targetFile,
                );
            }
        }
    }

    public function readlink(string $path, bool $canonicalize = false): ?string
    {
        if (!$canonicalize) {
            if (!File\isSymlink($path)) {
                return null;
            }

            try {
                return File\resolveSymlink($path);
            } catch (File\FilesystemException) {
                return null;
            }
        }

        if (!File\exists($path)) {
            return null;
        }

        // resolveSymlink resolves the symlink target.
        // For canonicalization, we resolve as much as possible.
        try {
            $resolved = File\resolveSymlink($path);
            if ($resolved !== $path) {
                return $resolved;
            }
        } catch (File\FilesystemException) {
            // Not a symlink or unresolvable
        }

        return $path;
    }

    public function mirror(
        string $originDir,
        string $targetDir,
        ?\Traversable $iterator = null,
        array $options = [],
    ): void {
        $targetDir = \rtrim($targetDir, '/\\');
        $originDir = \rtrim($originDir, '/\\');
        $originDirLen = \strlen($originDir);

        if (!File\exists($originDir)) {
            throw new IOException(
                \sprintf('The origin directory specified "%s" was not found.', $originDir),
                0,
                null,
                $originDir,
            );
        }

        // Delete obsolete entries in target if requested
        if (File\exists($targetDir) && ($options['delete'] ?? false)) {
            $this->removeObsoleteInMirror($originDir, $targetDir);
        }

        $this->mkdir($targetDir);
        $this->mirrorRecursive($originDir, $targetDir, $originDirLen, $options);
    }

    private function mirrorRecursive(string $originDir, string $targetDir, int $originDirLen, array $options): void
    {
        try {
            $children = File\listFiles($originDir);
        } catch (File\FilesystemException $e) {
            throw new IOException(
                \sprintf('Failed to list directory "%s": ', $originDir) . $e->getMessage(),
                0,
                $e,
                $originDir,
            );
        }

        foreach ($children as $child) {
            $childPath = $originDir . '/' . \basename($child);
            $targetPath = $targetDir . \substr($childPath, $originDirLen);

            if (File\isSymlink($childPath)) {
                $linkTarget = File\resolveSymlink($childPath);
                File\createSymlink($linkTarget, $targetPath);
            } elseif (File\isDirectory($childPath)) {
                $this->mkdir($targetPath);
                $this->mirrorRecursive($childPath, $targetPath, $originDirLen, $options);
            } elseif (File\isFile($childPath)) {
                $this->copy($childPath, $targetPath, $options['override'] ?? false);
            }
        }
    }

    private function removeObsoleteInMirror(string $originDir, string $targetDir): void
    {
        try {
            $targetChildren = File\listFiles($targetDir);
        } catch (File\FilesystemException) {
            return;
        }

        foreach ($targetChildren as $child) {
            $childPath = $targetDir . '/' . \basename($child);
            $originPath = $originDir . '/' . \basename($child);

            if (!File\exists($originPath)) {
                $this->remove($childPath);
            } elseif (File\isDirectory($childPath)) {
                $this->removeObsoleteInMirror($originPath, $childPath);
            }
        }
    }

    public function dumpFile(string $filename, $content): void
    {
        if (\is_array($content)) {
            throw new \TypeError(\sprintf(
                'Argument 2 passed to "%s()" must be string or resource, array given.',
                __METHOD__,
            ));
        }

        $dir = \dirname($filename);

        // Resolve symlinks
        if (File\isSymlink($filename)) {
            try {
                $linkTarget = File\resolveSymlink($filename);
                $this->dumpFile(Path::makeAbsolute($linkTarget, $dir), $content);

                return;
            } catch (File\FilesystemException) {
                // Not a symlink or unresolvable — fall through
            }
        }

        if (!File\isDirectory($dir)) {
            $this->mkdir($dir);
        }

        // Create a temp file in the same directory (atomic rename)
        $tmpFile = $this->tempnam($dir, \basename($filename));

        try {
            if (\is_resource($content)) {
                $content = \stream_get_contents($content);
            }

            File\write($tmpFile, $content);

            // Copy permissions from existing file
            if (File\exists($filename)) {
                try {
                    $perms = File\getStatus($filename)['mode'] ?? 0o666 & ~\umask();
                    File\changePermissions($tmpFile, $perms & 0o777);
                } catch (File\FilesystemException) {
                    File\changePermissions($tmpFile, 0o666 & ~\umask());
                }
            } else {
                File\changePermissions($tmpFile, 0o666 & ~\umask());
            }

            File\move($tmpFile, $filename);
        } catch (\Throwable $e) {
            // Clean up temp file
            if (File\exists($tmpFile)) {
                File\deleteFile($tmpFile);
            }

            if ($e instanceof File\FilesystemException) {
                throw new IOException(
                    \sprintf('Failed to write file "%s": ', $filename) . $e->getMessage(),
                    0,
                    $e,
                    $filename,
                );
            }

            throw $e;
        }

        // Clean up temp file if rename didn't move it (shouldn't happen, but safety net)
        if (File\exists($tmpFile)) {
            File\deleteFile($tmpFile);
        }
    }

    public function appendToFile(string $filename, $content, bool $lock = false): void
    {
        if (\is_array($content)) {
            throw new \TypeError(\sprintf(
                'Argument 2 passed to "%s()" must be string or resource, array given.',
                __METHOD__,
            ));
        }

        $dir = \dirname($filename);

        if (!File\isDirectory($dir)) {
            $this->mkdir($dir);
        }

        if (\is_resource($content)) {
            $content = \stream_get_contents($content);
        }

        try {
            $existing = '';
            if (File\exists($filename)) {
                $existing = File\read($filename);
            }

            File\write($filename, $existing . $content);
        } catch (File\FilesystemException $e) {
            throw new IOException(
                \sprintf('Failed to write file "%s": ', $filename) . $e->getMessage(),
                0,
                $e,
                $filename,
            );
        }
    }

    public function readFile(string $filename): string
    {
        if (File\isDirectory($filename)) {
            throw new IOException(\sprintf('Failed to read file "%s": File is a directory.', $filename));
        }

        try {
            return File\read($filename);
        } catch (File\FilesystemException $e) {
            throw new IOException(
                \sprintf('Failed to read file "%s": ', $filename) . $e->getMessage(),
                0,
                $e,
                $filename,
            );
        }
    }

    private function toIterable(string|iterable $files): iterable
    {
        return \is_iterable($files) ? $files : [$files];
    }
}
