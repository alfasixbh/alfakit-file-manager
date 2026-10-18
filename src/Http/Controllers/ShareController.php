<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Http\Controllers;

use AlfaSix\AlfakitFileManager\FileManagerPlugin;
use AlfaSix\AlfakitFileManager\Models\FileManagerLink;
use AlfaSix\AlfakitFileManager\Support\FileResponse;
use AlfaSix\AlfakitFileManager\Support\PathGuard;
use AlfaSix\AlfakitFileManager\Support\SignedFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Shared files, for anyone with the link: signed temporary URLs (`share`) and stored public
 * links (`link/{token}`). The link names the panel, disk, root and path; each is checked
 * again (the panel still has the plugin with sharing on and the disk, the path is safe and
 * under the root, the file exists), so turning sharing off or deleting the file ends every
 * link at once.
 */
class ShareController
{
    public function share(Request $request): Response
    {
        $target = SignedFiles::shareTarget($request->query('file'));

        abort_if($target === null, 404);

        return $this->send($target['panel'], $target['disk'], $target['root'], $target['path'], publicLink: false);
    }

    public function link(string $token): Response
    {
        $link = FileManagerLink::findByToken($token);

        abort_if($link === null || $link->isExpired(), 404);

        $response = $this->send($link->panel, $link->disk, $link->root, $link->path, publicLink: true);

        $link->forceFill(['downloads' => $link->downloads + 1, 'last_accessed_at' => now()])->save();

        return $response;
    }

    protected function send(string $panelId, string $disk, string $root, string $path, bool $publicLink): Response
    {
        $plugin = $this->plugin($panelId);
        $root = PathGuard::normalize($root);
        $path = PathGuard::normalize($path);

        abort_if($plugin === null || ! $plugin->hasShareLinks() || ($publicLink && ! $plugin->hasPublicLinks()), 404);
        abort_if($root === null || $path === null || $path === '' || ! $plugin->hasDisk($disk), 404);

        $filesystem = Storage::disk($disk);
        $full = PathGuard::join($root, $path);

        abort_unless($filesystem->fileExists($full), 404);

        return FileResponse::make($filesystem, $full, PathGuard::basename($path), inline: true);
    }

    protected function plugin(string $panelId): ?FileManagerPlugin
    {
        try {
            $panel = alfakit()->getPanel($panelId);
        } catch (Throwable) {
            return null;
        }

        if (! $panel->hasPlugin(FileManagerPlugin::ID)) {
            return null;
        }

        $plugin = $panel->getPlugin(FileManagerPlugin::ID);

        return $plugin instanceof FileManagerPlugin ? $plugin : null;
    }
}
