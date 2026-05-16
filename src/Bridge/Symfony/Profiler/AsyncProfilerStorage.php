<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Profiler;

use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;

use function Amp\File\createDirectoryRecursively;
use function Amp\File\deleteFile;
use function Amp\File\isDirectory;
use function Amp\File\isFile;
use function Amp\File\read;
use function Amp\File\write;

final class AsyncProfilerStorage extends FileProfilerStorage
{
    private const int PROFILE_LIFETIME = 2 * 86_400;

    public function write(Profile $profile): bool
    {
        $file = $this->getFilename($profile->getToken());

        $profileIndexed = isFile($file);

        if (!$profileIndexed) {
            $dir = \dirname($file);
            if (!isDirectory($dir)) {
                try {
                    createDirectoryRecursively($dir);
                } catch (\Throwable) {
                    // Race condition: another fiber may have created it
                    if (!isDirectory($dir)) {
                        return false;
                    }
                }
            }
        }

        $profileToken = $profile->getToken();
        // When there are errors in sub-requests, the parent and/or children tokens
        // may equal the profile token, resulting in infinite loops
        $parentToken = $profile->getParentToken() !== $profileToken ? $profile->getParentToken() : null;
        $childrenToken = array_filter(array_map(static fn(Profile $p) => $profileToken !== $p->getToken()
            ? $p->getToken()
            : null, $profile->getChildren()));

        // Store profile
        $data = [
            'token' => $profileToken,
            'parent' => $parentToken,
            'children' => $childrenToken,
            'data' => $profile->getCollectors(),
            'ip' => $profile->getIp(),
            'method' => $profile->getMethod(),
            'url' => $profile->getUrl(),
            'time' => $profile->getTime(),
            'status_code' => $profile->getStatusCode(),
            'virtual_type' => $profile->getVirtualType() ?? 'request',
        ];

        $data = serialize($data);

        if (\function_exists('gzencode')) {
            $data = gzencode($data, 3);
        }

        try {
            write($file, $data);
        } catch (\Throwable) {
            return false;
        }

        if (!$profileIndexed) {
            // Append to index (read-modify-write)
            $this->asyncAppendToIndex($profile);

            if (1 === \random_int(1, 10)) {
                $this->asyncRemoveExpiredProfiles();
            }
        }

        return true;
    }

    public function read(#[\SensitiveParameter] string $token): ?Profile
    {
        $file = $this->getFilename($token);

        if (!isFile($file)) {
            return null;
        }

        try {
            $data = read($file);
        } catch (\Throwable) {
            return null;
        }

        if (\function_exists('gzdecode')) {
            $data = @gzdecode($data) ?: $data;
        }

        if (!($data = \unserialize($data))) {
            return null;
        }

        return $this->createProfileFromData($token, $data);
    }

    private function asyncAppendToIndex(Profile $profile): void
    {
        $line = \sprintf(
            "%s,%s,%s,%s,%s,%s,%s,%s\n",
            self::escapeCsv($profile->getToken()),
            self::escapeCsv($profile->getIp()),
            self::escapeCsv($profile->getMethod()),
            self::escapeCsv($profile->getUrl()),
            self::escapeCsv((string) ($profile->getTime() ?: \time())),
            self::escapeCsv($profile->getParentToken() ?? ''),
            self::escapeCsv((string) $profile->getStatusCode()),
            self::escapeCsv($profile->getVirtualType() ?? 'request'),
        );

        try {
            $indexFile = $this->getIndexFilename();
            $existing = '';
            if (isFile($indexFile)) {
                $existing = read($indexFile);
            }
            write($indexFile, $existing . $line);
        } catch (\Throwable) {
            // Index write failure is non-fatal — the profile data file still exists
        }
    }

    private static function escapeCsv(string $value): string
    {
        if (
            \str_contains($value, ',')
            || \str_contains($value, '"')
            || \str_contains($value, '\\')
            || \str_contains($value, "\n")
        ) {
            return '"' . \str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }

    private function asyncRemoveExpiredProfiles(): void
    {
        $minimalProfileTimestamp = \time() - self::PROFILE_LIFETIME;
        $indexFile = $this->getIndexFilename();

        try {
            if (!isFile($indexFile)) {
                return;
            }

            $contents = read($indexFile);
        } catch (\Throwable) {
            return;
        }

        $lines = \explode("\n", $contents);
        $keep = [];

        foreach ($lines as $line) {
            $trimmed = \trim($line);

            if ('' === $trimmed) {
                continue;
            }

            $values = \str_getcsv($line, ',', '"', '\\');

            if (7 > \count($values)) {
                // Skip invalid lines (leave them in place to avoid corruption)
                $keep[] = $line;
                continue;
            }

            [$csvToken, , , , $csvTime] = $values;

            if ((int) $csvTime < $minimalProfileTimestamp) {
                // Remove the profile data file
                try {
                    deleteFile($this->getFilename($csvToken));
                } catch (\Throwable) {
                    // File may not exist or already deleted
                }
            } else {
                $keep[] = $line;
            }
        }

        // Rewrite the index file without expired entries
        try {
            write($indexFile, \implode("\n", $keep) . "\n");
        } catch (\Throwable) {
            // Non-fatal
        }
    }
}
