<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Support;

use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\AlfakitFileManager\Pages\FileManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * The links the file manager hands out.
 *
 * - Files (`{page}/file`): signed, expiring panel routes with the disk and the path under the
 *   root. The root is never in the URL: the controller works it out again for the signed-in
 *   user's tenant, so a link only opens the file for users who may see it. Expiries are
 *   rounded to ten minutes, so thumbnails keep the same URL and stay in the browser cache.
 * - Zip archives (`{page}/zip`): the selection is kept in the cache for the signed-in user;
 *   the URL only carries its key.
 * - Shares (`/{prefix}/share`): signed temporary URLs anyone can open, with the disk, root
 *   and path encrypted.
 * - Public links (`/{prefix}/link/{token}`): stored, revocable links.
 */
final class SignedFiles
{
    public const INLINE = 'inline';

    public const DOWNLOAD = 'download';

    private const BUCKET_SECONDS = 600;

    public static function fileUrl(FileManager $page, string $disk, string $path, string $mode): string
    {
        $lifetime = FileManager::getPlugin()->getSignedUrlLifetime() * 60;
        $expires = (int) (ceil((time() + $lifetime) / self::BUCKET_SECONDS) * self::BUCKET_SECONDS);

        return URL::temporarySignedRoute(
            self::routeName($page, 'file'),
            Carbon::createFromTimestamp($expires),
            [...self::tenantParameters($page), 'disk' => $disk, 'path' => $path, 'mode' => $mode === self::DOWNLOAD ? 'd' : 'i'],
        );
    }

    /**
     * @param  list<string>  $paths
     */
    public static function zipUrl(FileManager $page, string $disk, array $paths): string
    {
        $key = Str::random(40);
        $minutes = min(60, FileManager::getPlugin()->getSignedUrlLifetime());

        Cache::put(self::zipCacheKey($key), [
            'panel' => $page->getPanel()->getId(),
            'user' => SchemaContext::currentUser(),
            'disk' => $disk,
            'paths' => $paths,
        ], now()->addMinutes($minutes));

        return URL::temporarySignedRoute(self::routeName($page, 'zip'), now()->addMinutes($minutes), [...self::tenantParameters($page), 'key' => $key]);
    }

    /**
     * @return array{panel: string, user: string|null, disk: string, paths: list<string>}|null
     */
    public static function zipSelection(mixed $key): ?array
    {
        if (! is_string($key) || preg_match('/^[A-Za-z0-9]{40}$/', $key) !== 1) {
            return null;
        }

        $selection = Cache::get(self::zipCacheKey($key));

        if (! is_array($selection) || ! is_string($selection['panel'] ?? null) || ! is_string($selection['disk'] ?? null) || ! is_array($selection['paths'] ?? null)) {
            return null;
        }

        return [
            'panel' => $selection['panel'],
            'user' => is_string($selection['user'] ?? null) ? $selection['user'] : null,
            'disk' => $selection['disk'],
            'paths' => array_values(array_filter($selection['paths'], is_string(...))),
        ];
    }

    public static function shareUrl(string $panel, string $disk, string $root, string $path, Carbon $expiresAt): string
    {
        return URL::temporarySignedRoute('alfakit-file-manager.share', $expiresAt, [
            'file' => Crypt::encryptString(json_encode(['panel' => $panel, 'disk' => $disk, 'root' => $root, 'path' => $path], JSON_THROW_ON_ERROR)),
        ]);
    }

    /**
     * @return array{panel: string, disk: string, root: string, path: string}|null
     */
    public static function shareTarget(mixed $payload): ?array
    {
        if (! is_string($payload)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($payload), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        foreach (['panel', 'disk', 'root', 'path'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                return null;
            }
        }

        /** @var array{panel: string, disk: string, root: string, path: string} $data */
        return $data;
    }

    public static function publicLinkUrl(string $token): string
    {
        return route('alfakit-file-manager.link', ['token' => $token]);
    }

    private static function routeName(FileManager $page, string $suffix): string
    {
        return $page->getPanel()->generateRouteName($page::getRelativeRouteName().'.'.$suffix);
    }

    /**
     * @return array<string, mixed>
     */
    private static function tenantParameters(FileManager $page): array
    {
        $panel = $page->getPanel();
        $tenant = $panel->hasTenancy() ? alfakit()->getTenant() : null;

        return $tenant === null ? [] : ['tenant' => $panel->getTenantRouteKey($tenant)];
    }

    private static function zipCacheKey(string $key): string
    {
        return 'alfakit-file-manager:zip:'.$key;
    }
}
