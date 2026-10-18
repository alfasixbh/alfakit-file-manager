<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Sends a stored file to the browser.
 *
 * Only types that are safe on the panel's origin (images other than SVG, audio, video, PDF,
 * plain text) are ever shown inline; everything else downloads. Responses never let the
 * browser sniff another type, and inline files run in a sandbox without scripts (except PDFs,
 * which the browsers' viewers can't show sandboxed). Local files support range requests, so
 * players can seek.
 */
final class FileResponse
{
    public static function make(Filesystem $filesystem, string $path, string $name, bool $inline, int $maxAge = 0): Response
    {
        $mime = self::mimeType($filesystem, $path, $name);
        $inline = $inline && FileTypes::isSafeInline($mime);
        $type = $inline ? (str_starts_with((string) $mime, 'text/') ? 'text/plain; charset=UTF-8' : (string) $mime) : 'application/octet-stream';
        $fallback = preg_replace('/[^\x20-\x7E]|[%\/\\\\"]/', '_', $name) ?? 'download';

        $headers = [
            'Content-Type' => $type,
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback === '' ? 'download' : $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $maxAge > 0 ? 'private, max-age='.$maxAge : 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        if ($mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox";
        }

        $local = self::localPath($filesystem, $path);

        if ($local !== null) {
            $response = new BinaryFileResponse($local, 200, $headers, public: false, autoEtag: false, autoLastModified: true);
            $response->headers->set('Content-Type', $type);
            $response->headers->set('Content-Disposition', $headers['Content-Disposition']);

            return $response;
        }

        $size = null;

        try {
            $size = $filesystem->size($path);
        } catch (Throwable) {
            // Unknown size: sent without Content-Length.
        }

        if ($size !== null) {
            $headers['Content-Length'] = (string) $size;
        }

        return new StreamedResponse(static function () use ($filesystem, $path): void {
            $stream = $filesystem->readStream($path);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, $headers);
    }

    private static function mimeType(Filesystem $filesystem, string $path, string $name): ?string
    {
        try {
            $mime = $filesystem->mimeType($path);
        } catch (Throwable) {
            $mime = false;
        }

        return is_string($mime) && $mime !== '' ? strtolower($mime) : FileTypes::guessMimeType($name);
    }

    private static function localPath(Filesystem $filesystem, string $path): ?string
    {
        if (! $filesystem instanceof FilesystemAdapter) {
            return null;
        }

        try {
            $local = $filesystem->path($path);
        } catch (Throwable) {
            return null;
        }

        return is_file($local) ? $local : null;
    }
}
