<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\HttpFoundation\File;

use Amp\File as AmpFile;
use Symfony\Component\HttpFoundation\File\Exception\CannotWriteFileException;
use Symfony\Component\HttpFoundation\File\Exception\ExtensionFileException;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\Exception\FormSizeFileException;
use Symfony\Component\HttpFoundation\File\Exception\IniSizeFileException;
use Symfony\Component\HttpFoundation\File\Exception\NoFileException;
use Symfony\Component\HttpFoundation\File\Exception\NoTmpDirFileException;
use Symfony\Component\HttpFoundation\File\Exception\PartialFileException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AsyncUploadedFile extends UploadedFile
{
    private const string DUMMY_PATH = '/dev/null';

    public function __construct(
        string $originalName,
        string $mimeType,
        private readonly string $content,
        int $error = \UPLOAD_ERR_OK,
        bool $test = true,
    ) {
        // To bypass File::__construct()'s is_file() check, we temporarily
        // set a non-OK error code so checkPath=false, then fix it.
        $tempError = $error !== \UPLOAD_ERR_OK ? $error : \UPLOAD_ERR_NO_FILE;

        parent::__construct(
            path: self::DUMMY_PATH,
            originalName: $originalName,
            mimeType: $mimeType,
            error: $tempError,
            test: $test,
        );

        // Restore the original error code via closure binding to
        // access UploadedFile's private $error property.
        if ($tempError !== $error) {
            \Closure::bind(
                function () use ($error): void {
                    $this->error = $error;
                },
                $this,
                UploadedFile::class,
            )();
        }
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getSize(): int
    {
        return \strlen($this->content);
    }

    public function getBasename(string $suffix = ''): string
    {
        return $suffix !== '' ? $this->getClientOriginalName() . $suffix : $this->getClientOriginalName();
    }

    public function getRealPath(): string|false
    {
        return false;
    }

    public function isValid(): bool
    {
        return \UPLOAD_ERR_OK === $this->getError();
    }

    public function getMimeType(): ?string
    {
        return $this->getClientMimeType();
    }

    public function move(string $directory, ?string $name = null): File
    {
        if (!$this->isValid()) {
            throw self::createExceptionForError($this->getError(), $this->getClientOriginalName());
        }

        $target = \rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . ($name ?? $this->getBasename());

        try {
            // Ensure the target directory exists (non-blocking)
            AmpFile\createDirectoryRecursively($directory, 0o777);

            // Write the file content asynchronously (non-blocking)
            AmpFile\write($target, $this->content);

            // Set file permissions (non-blocking)
            AmpFile\changePermissions($target, 0o666 & ~\umask());
        } catch (\Throwable $e) {
            throw new FileException(
                \sprintf(
                    'Could not move the file "%s" to "%s" (%s).',
                    $this->getClientOriginalName(),
                    $target,
                    $e->getMessage(),
                ),
                0,
                $e,
            );
        }

        return new File($target, false);
    }

    private static function createExceptionForError(int $error, string $originalName): FileException
    {
        return match ($error) {
            \UPLOAD_ERR_INI_SIZE => new IniSizeFileException(\sprintf(
                'The file "%s" exceeds your upload_max_filesize ini directive.',
                $originalName,
            )),
            \UPLOAD_ERR_FORM_SIZE => new FormSizeFileException(\sprintf(
                'The file "%s" exceeds the upload limit defined in your form.',
                $originalName,
            )),
            \UPLOAD_ERR_PARTIAL => new PartialFileException(\sprintf(
                'The file "%s" was only partially uploaded.',
                $originalName,
            )),
            \UPLOAD_ERR_NO_FILE => new NoFileException('No file was uploaded.'),
            \UPLOAD_ERR_CANT_WRITE => new CannotWriteFileException(\sprintf(
                'The file "%s" could not be written on disk.',
                $originalName,
            )),
            \UPLOAD_ERR_NO_TMP_DIR => new NoTmpDirFileException(
                'File could not be uploaded: missing temporary directory.',
            ),
            \UPLOAD_ERR_EXTENSION => new ExtensionFileException('File upload was stopped by a PHP extension.'),
            default => new FileException(\sprintf(
                'The file "%s" was not uploaded due to an unknown error.',
                $originalName,
            )),
        };
    }
}
