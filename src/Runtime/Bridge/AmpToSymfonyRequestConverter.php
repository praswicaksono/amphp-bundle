<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bridge;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\FormParser\BufferedFile;
use Amp\Http\Server\FormParser\FormParser;
use Amp\Http\Server\Request;
use PRSW\AmphpBundle\Bridge\Symfony\HttpFoundation\File\AsyncUploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

use function Amp\Http\Server\FormParser\parseContentBoundary;

final class AmpToSymfonyRequestConverter
{
    /**
     * Convert an AMPHP request to a Symfony request.
     *
     * @param Request  $request    The AMPHP request to convert
     * @param int|null $maxBodySize Maximum allowed body size in bytes (null = no limit)
     *
     * @return SymfonyRequest
     *
     * @throws \Amp\Http\Server\ClientException If the body exceeds max_body_size (returns 413)
     */
    public static function convert(Request $request, ?int $maxBodySize = null): SymfonyRequest
    {
        $method = $request->getMethod();
        $uri = $request->getUri();
        $contentType = $request->getHeader('content-type') ?? '';
        $headers = $request->getHeaders();

        // Enforce body size limit before buffering
        if ($maxBodySize !== null && $maxBodySize > 0) {
            $contentLengthHeader = $request->getHeader('content-length');
            if ($contentLengthHeader !== null) {
                $contentLength = (int) $contentLengthHeader;
                if ($contentLength > $maxBodySize) {
                    throw new BodySizeExceededException($contentLength, $maxBodySize);
                }
            }
        }

        // Buffer the entire body first so we can work with it
        try {
            $bodyString = $request->getBody()->buffer(limit: $maxBodySize ?? \PHP_INT_MAX);
        } catch (BufferException $e) {
            throw new BodySizeExceededException(
                (int) ($request->getHeader('content-length') ?? 0),
                $maxBodySize ?? \PHP_INT_MAX,
                $e,
            );
        }

        // Build flat cookies array
        $cookies = [];
        foreach ($request->getCookies() as $name => $cookie) {
            $cookies[$name] = $cookie->getValue();
        }

        // Build server params
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => (string) $uri,
            'SERVER_PROTOCOL' => 'HTTP/' . $request->getProtocolVersion(),
            'QUERY_STRING' => $uri->getQuery() ?? '',
            'SERVER_NAME' => $uri->getHost() ?? 'localhost',
            'SERVER_PORT' => (string) ($uri->getPort() ?? ('https' === $uri->getScheme() ? 443 : 80)),
            'REQUEST_SCHEME' => $uri->getScheme() ?? 'http',
            'HTTPS' => 'https' === $uri->getScheme() ? 'on' : 'off',
            'CONTENT_TYPE' => $contentType,
            'CONTENT_LENGTH' => $request->getHeader('content-length') ?? (string) \strlen($bodyString),
        ];

        // Add client address if available
        try {
            $client = $request->getClient();
            $remoteAddr = $client->getRemoteAddress();
            $server['REMOTE_ADDR'] = $remoteAddr->getAddress();
            $server['REMOTE_PORT'] = (string) $remoteAddr->getPort();
        } catch (\Throwable) {
            // Client info may not be available
        }

        // Parse form data if applicable (urlencoded or multipart)
        $boundary = parseContentBoundary($contentType);
        $hasFormData = $boundary !== null;

        if ($hasFormData) {
            $formParser = new FormParser();
            $form = $formParser->parseBody($bodyString, $boundary);

            $requestParams = self::buildRequestParams($form->getValues());
            $symfonyFiles = self::buildSymfonyFiles($form->getFiles());

            $bodyContent = null; // Body is fully consumed into form fields/files
        } else {
            $requestParams = [];
            $symfonyFiles = [];
            $bodyContent = $bodyString;
        }

        $sfRequest = new SymfonyRequest(
            query: self::buildRequestParams($request->getQueryParameters()),
            request: $requestParams,
            attributes: [],
            cookies: $cookies,
            files: $symfonyFiles,
            server: $server,
            content: $bodyContent,
        );

        // Set headers
        foreach ($headers as $name => $values) {
            // Skip headers that are already handled by server params
            if (\in_array(\strtolower($name), ['content-type', 'content-length'], true)) {
                continue;
            }

            $sfRequest->headers->set($name, $values, false);
        }

        // Set content-type and content-length separately (skipped above to avoid double-setting)
        if (isset($headers['content-type'])) {
            $sfRequest->headers->set('Content-Type', $headers['content-type'], false);
        }
        if (isset($headers['content-length'])) {
            $sfRequest->headers->set('Content-Length', $headers['content-length'], false);
        }

        return $sfRequest;
    }

    /**
     * Convert AMPHP form field values to a flat Symfony request parameters array.
     *
     * @param array<non-empty-string, list<string>> $fields
     *
     * @return array<string, mixed>
     */
    private static function buildRequestParams(array $fields): array
    {
        $params = [];

        foreach ($fields as $name => $values) {
            $params[$name] = \count($values) === 1 ? $values[0] : $values;
        }

        return $params;
    }

    /**
     * Convert AMPHP uploaded files to Symfony AsyncUploadedFile objects.
     *
     * Unlike the default UploadedFile, AsyncUploadedFile stores content
     * in memory and uses non-blocking AMPHP file I/O when moving files.
     *
     * @param array<string, list<BufferedFile>> $files
     *
     * @return array<string, AsyncUploadedFile|list<AsyncUploadedFile>>
     */
    private static function buildSymfonyFiles(array $files): array
    {
        $symfonyFiles = [];

        foreach ($files as $fieldName => $fileList) {
            $result = [];

            foreach ($fileList as $file) {
                $result[] = new AsyncUploadedFile(
                    originalName: $file->getName(),
                    mimeType: $file->getMimeType(),
                    content: $file->getContents(),
                );
            }

            // Single file → single object; multiple files → array
            $symfonyFiles[$fieldName] = \count($result) === 1 ? $result[0] : $result;
        }

        return $symfonyFiles;
    }
}
