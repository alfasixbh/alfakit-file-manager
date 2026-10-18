<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Http\Controllers;

use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\AlfakitFileManager\FileManagerPlugin;
use AlfaSix\AlfakitFileManager\Support\Drive;
use AlfaSix\AlfakitFileManager\Support\FileResponse;
use AlfaSix\AlfakitFileManager\Support\PathGuard;
use AlfaSix\AlfakitFileManager\Support\SignedFiles;
use AlfaSix\AlfakitFileManager\Support\ZipArchiver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The file manager's signed panel routes: one file (inline or as a download) and zip
 * archives. They sit behind the panel's authentication and tenant middleware and check the
 * plugin's authorisation again; the root comes from the current tenant, never from the URL.
 * Downloads and archives are rate limited per user.
 */
class FileController
{
    public function show(Request $request): Response
    {
        $plugin = FileManagerPlugin::get();
        abort_unless($plugin->isAuthorized(), 403);

        $disk = $request->query('disk');
        $path = PathGuard::normalize($request->query('path'));
        abort_unless($plugin->hasDisk($disk) && $path !== null && $path !== '', 404);

        $download = $request->query('mode') === 'd';
        $drive = new Drive($plugin, (string) $disk);

        abort_unless($drive->isFile($path), 404);

        if ($download) {
            abort_unless($plugin->allows('download', $drive->disk(), $path), 403);
            $this->throttle('download:'.$this->bucket($request), $plugin->getDownloadRateLimit());
        }

        return FileResponse::make($drive->filesystem(), $drive->full($path), PathGuard::basename($path), inline: ! $download, maxAge: $download ? 0 : 600);
    }

    public function zip(Request $request): Response
    {
        $plugin = FileManagerPlugin::get();
        abort_unless($plugin->isAuthorized() && $plugin->hasZipDownloads(), 403);

        $selection = SignedFiles::zipSelection($request->query('key'));
        $panel = alfakit()->getCurrentOrDefaultPanel();

        abort_if($selection === null || $selection['panel'] !== $panel->getId() || $selection['user'] !== SchemaContext::currentUser(), 404);
        abort_unless($plugin->hasDisk($selection['disk']), 404);

        $drive = new Drive($plugin, $selection['disk']);
        $paths = array_values(array_filter(array_map(PathGuard::normalize(...), $selection['paths']), static fn (?string $path): bool => $path !== null && $path !== ''));

        abort_unless($plugin->allows('download', $drive->disk(), '', $paths), 403);
        $this->throttle('zip:'.$this->bucket($request), $plugin->getZipRateLimit());

        try {
            $plan = ZipArchiver::plan($drive, $paths, $plugin);
        } catch (ValidationException $exception) {
            abort(422, (string) collect($exception->errors())->flatten()->first());
        }

        $temporary = [];
        $archive = ZipArchiver::build($drive, $plan['files'], $temporary);
        $name = count($paths) === 1 ? PathGuard::basename($paths[0]).'.zip' : __('alfakit-file-manager::file-manager.zip_name').'.zip';

        app()->terminating(static function () use ($temporary): void {
            foreach ($temporary as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        });

        $response = new BinaryFileResponse($archive, 200, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], public: false);

        return $response->setContentDisposition('attachment', $name, preg_replace('/[^\x20-\x7E]|[%\/\\\\"]/', '_', $name) ?? 'files.zip')->deleteFileAfterSend();
    }

    protected function bucket(Request $request): string
    {
        $user = SchemaContext::currentUser();

        return sha1(alfakit()->getCurrentOrDefaultPanel()->getId().'|'.($user !== null ? 'user:'.$user : 'ip:'.$request->ip()));
    }

    protected function throttle(string $key, int $perMinute): void
    {
        $key = 'alfakit-file-manager:'.$key;

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            abort(429, (string) __('alfakit-file-manager::file-manager.errors.rate_limited', ['seconds' => RateLimiter::availableIn($key)]), ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 60);
    }
}
