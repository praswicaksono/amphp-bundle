<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Session;

use Amp\File\File;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\AbstractSessionHandler;

final class AmpFileSessionHandler extends AbstractSessionHandler
{
    private const string SESSION_FILE_PREFIX = 'sess_';

    private string $savePath;

    public function __construct(?string $savePath = null)
    {
        $this->savePath = $savePath ?? \sys_get_temp_dir();
    }

    public function close(): bool
    {
        return true;
    }

    protected function doRead(#[\SensitiveParameter] string $sessionId): string
    {
        $path = $this->getSessionFilePath($sessionId);

        if (!\Amp\File\exists($path)) {
            return '';
        }

        try {
            $file = \Amp\File\openFile($path, 'r');
        } catch (\Throwable) {
            return '';
        }

        try {
            $contents = '';
            while (null !== ($chunk = $file->read())) {
                $contents .= $chunk;
            }
        } finally {
            $file->close();
        }

        return $contents;
    }

    protected function doWrite(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        $path = $this->getSessionFilePath($sessionId);

        // Ensure the save path directory exists
        if (!\Amp\File\exists($this->savePath)) {
            try {
                \Amp\File\createDirectory($this->savePath, 0o777);
            } catch (\Throwable) {
                return false;
            }
        }

        try {
            $file = \Amp\File\openFile($path, 'w');
        } catch (\Throwable) {
            return false;
        }

        try {
            $file->write($data);
        } catch (\Throwable) {
            return false;
        } finally {
            $file->close();
        }

        return true;
    }

    protected function doDestroy(#[\SensitiveParameter] string $sessionId): bool
    {
        $path = $this->getSessionFilePath($sessionId);

        try {
            \Amp\File\deleteFile($path);
        } catch (\Throwable) {
            // File may not exist
        }

        return true;
    }

    public function gc(int $maxlifetime): int|false
    {
        try {
            $files = \scandir($this->savePath);
        } catch (\Throwable) {
            return 0;
        }

        if (false === $files) {
            return 0;
        }

        $count = 0;
        $now = \time();

        foreach ($files as $file) {
            if (!\str_starts_with($file, self::SESSION_FILE_PREFIX)) {
                continue;
            }

            $path = $this->savePath . '/' . $file;

            $mtime = \Amp\File\getModificationTime($path);
            if (($mtime + $maxlifetime) < $now) {
                try {
                    \Amp\File\deleteFile($path);
                    ++$count;
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        return $count;
    }

    public function updateTimestamp(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        // File modification time is automatically updated by doWrite().
        // We just need to perform the same write to update the timestamp.
        return $this->doWrite($sessionId, $data);
    }

    private function getSessionFilePath(string $sessionId): string
    {
        return $this->savePath . '/' . self::SESSION_FILE_PREFIX . $sessionId;
    }
}
