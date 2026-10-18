<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Support;

use AlfaSix\Alfakit\Support\TemporaryUpload;
use AlfaSix\AlfakitFileManager\FileManagerPlugin;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
use Throwable;

/**
 * One disk as the file manager sees it: everything under the root, addressed by paths
 * relative to it. Every method takes paths that went through `PathGuard` and turns them into
 * disk paths itself, so callers never handle a disk path, and nothing outside the root
 * (or inside the hidden trash) is ever listed, read or written.
 *
 * Folders are moved and copied file by file, so the same code works on local disks, S3 and
 * any other Flysystem adapter.
 */
class Drive
{
    /** The hidden folder under the root that holds deleted items. */
    public const TRASH = '.trash';

    private const TRASH_ID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    protected Filesystem $filesystem;

    protected string $root;

    public function __construct(protected FileManagerPlugin $plugin, protected string $disk, ?string $root = null)
    {
        abort_unless($plugin->hasDisk($disk), 404);

        $this->filesystem = Storage::disk($disk);
        $this->root = $root ?? $plugin->getRoot($disk);
    }

    public function disk(): string
    {
        return $this->disk;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function filesystem(): Filesystem
    {
        return $this->filesystem;
    }

    /**
     * The disk path of a path relative to the root.
     */
    public function full(string $path): string
    {
        return PathGuard::join($this->root, $path);
    }

    /**
     * The path relative to the root of a disk path, or null when it is outside the root.
     */
    public function relative(string $full): ?string
    {
        $full = trim($full, '/');

        if ($this->root === '') {
            return $full;
        }

        if ($full === $this->root) {
            return '';
        }

        return str_starts_with($full, $this->root.'/') ? substr($full, strlen($this->root) + 1) : null;
    }

    public function isFile(string $path): bool
    {
        return $path !== '' && $this->filesystem->fileExists($this->full($path));
    }

    public function isFolder(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        return $this->filesystem->directoryExists($this->full($path));
    }

    public function exists(string $path): bool
    {
        return $this->isFile($path) || $this->isFolder($path);
    }

    /**
     * @throws ValidationException
     */
    public function ensureFolder(string $path, string $attribute = 'path'): void
    {
        if (! $this->isFolder($path)) {
            throw ValidationException::withMessages([$attribute => __('alfakit-file-manager::file-manager.errors.folder_not_found')]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function ensureExists(string $path, string $attribute = 'path'): void
    {
        if (! $this->exists($path)) {
            throw ValidationException::withMessages([$attribute => __('alfakit-file-manager::file-manager.errors.not_found')]);
        }
    }

    // ---------------------------------------------------------------- Listing

    /**
     * The folders and files directly in `$path`: folders first, then files, by name.
     *
     * @return array{entries: list<array{path: string, name: string, type: string, size: int|null, modified: int|null}>, truncated: bool}
     */
    public function list(string $path, ?int $limit = null): array
    {
        $limit ??= $this->plugin->getMaxListedEntries();
        $entries = [];
        $truncated = false;

        foreach ($this->contents($path, deep: false) as $attributes) {
            $entry = $this->toEntry($attributes);

            if ($entry === null) {
                continue;
            }

            if (count($entries) >= $limit) {
                $truncated = true;

                break;
            }

            $entries[] = $entry;
        }

        usort($entries, static fn (array $a, array $b): int => [$a['type'] === 'file', mb_strtolower($a['name'])] <=> [$b['type'] === 'file', mb_strtolower($b['name'])]);

        return ['entries' => $entries, 'truncated' => $truncated];
    }

    /**
     * The sub-folders of `$path`, with whether each has sub-folders of its own.
     *
     * @return list<array{path: string, name: string, hasChildren: bool}>
     */
    public function folders(string $path): array
    {
        $folders = [];

        foreach ($this->list($path)['entries'] as $entry) {
            if ($entry['type'] !== 'folder') {
                continue;
            }

            $folders[] = ['path' => $entry['path'], 'name' => $entry['name'], 'hasChildren' => $this->hasSubfolders($entry['path'])];
        }

        return $folders;
    }

    protected function hasSubfolders(string $path): bool
    {
        foreach ($this->contents($path, deep: false) as $attributes) {
            if ($attributes instanceof DirectoryAttributes && $this->toEntry($attributes) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Files and folders under `$path` (and, when `$recursive`, every folder below it) whose
     * name contains `$term`, case-insensitively. Stops at the plugin's result and scan limits.
     *
     * @return array{entries: list<array{path: string, name: string, type: string, size: int|null, modified: int|null}>, truncated: bool}
     */
    public function search(string $path, string $term, bool $recursive): array
    {
        $needle = mb_strtolower($term);
        $max = $this->plugin->getMaxSearchResults();
        $scanLimit = $this->plugin->getMaxScannedEntries();
        $entries = [];
        $scanned = 0;
        $truncated = false;

        foreach ($this->contents($path, deep: $recursive) as $attributes) {
            if (++$scanned > $scanLimit) {
                $truncated = true;

                break;
            }

            $entry = $this->toEntry($attributes);

            if ($entry === null || ! str_contains(mb_strtolower($entry['name']), $needle)) {
                continue;
            }

            if (count($entries) >= $max) {
                $truncated = true;

                break;
            }

            $entries[] = $entry;
        }

        usort($entries, static fn (array $a, array $b): int => [$a['type'] === 'file', mb_strtolower($a['name'])] <=> [$b['type'] === 'file', mb_strtolower($b['name'])]);

        return ['entries' => $entries, 'truncated' => $truncated];
    }

    /**
     * One file or folder.
     *
     * @return array{path: string, name: string, type: string, size: int|null, modified: int|null}|null
     */
    public function entry(string $path): ?array
    {
        if ($path === '') {
            return null;
        }

        $full = $this->full($path);

        if ($this->filesystem->fileExists($full)) {
            return [
                'path' => $path,
                'name' => PathGuard::basename($path),
                'type' => 'file',
                'size' => $this->safely(fn (): int => $this->filesystem->size($full)),
                'modified' => $this->safely(fn (): int => $this->filesystem->lastModified($full)),
            ];
        }

        if ($this->filesystem->directoryExists($full)) {
            return ['path' => $path, 'name' => PathGuard::basename($path), 'type' => 'folder', 'size' => null, 'modified' => $this->safely(fn (): int => $this->filesystem->lastModified($full))];
        }

        return null;
    }

    /**
     * The type detected by the disk (from the contents on local disks).
     */
    public function mimeType(string $path): ?string
    {
        $mime = $this->safely(fn (): string|false => $this->filesystem->mimeType($this->full($path)));

        return is_string($mime) && $mime !== '' ? $mime : FileTypes::guessMimeType($path);
    }

    /**
     * @return resource|null
     */
    public function readStream(string $path)
    {
        $stream = $this->safely(fn (): mixed => $this->filesystem->readStream($this->full($path)));

        return is_resource($stream) ? $stream : null;
    }

    /**
     * At most `$bytes` of a file.
     */
    public function readStart(string $path, int $bytes): string
    {
        $stream = $this->readStream($path);

        if ($stream === null) {
            return '';
        }

        $contents = stream_get_contents($stream, $bytes);
        fclose($stream);

        return $contents === false ? '' : $contents;
    }

    /**
     * The absolute path of a file on a local disk, for `ZipArchive` and image dimensions.
     */
    public function localPath(string $path): ?string
    {
        if (! $this->filesystem instanceof FilesystemAdapter) {
            return null;
        }

        try {
            $local = $this->filesystem->path($this->full($path));
        } catch (Throwable) {
            return null;
        }

        return is_file($local) ? $local : null;
    }

    /**
     * `[width, height]` of an image, or null.
     *
     * @return array{0: int, 1: int}|null
     */
    public function imageDimensions(string $path): ?array
    {
        $local = $this->localPath($path);
        $size = $local !== null ? @getimagesize($local) : @getimagesizefromstring($this->readStart($path, 16 * 1024 * 1024));

        return is_array($size) && $size[0] > 0 && $size[1] > 0 ? [(int) $size[0], (int) $size[1]] : null;
    }

    // ---------------------------------------------------------------- Changes

    /**
     * @throws ValidationException
     *
     * @return string The new folder's path
     */
    public function createFolder(string $parent, string $name): string
    {
        $this->ensureFolder($parent);
        $path = PathGuard::join($parent, $name);

        if ($this->exists($path)) {
            throw ValidationException::withMessages(['name' => __('alfakit-file-manager::file-manager.errors.exists', ['name' => $name])]);
        }

        $this->filesystem->makeDirectory($this->full($path));

        return $path;
    }

    /**
     * @throws ValidationException
     *
     * @return string The new path
     */
    public function rename(string $path, string $name): string
    {
        $this->ensureExists($path);
        $target = PathGuard::join(PathGuard::parent($path), $name);

        if ($target === $path) {
            return $path;
        }

        // A change of case only (`a.txt` → `A.txt`) is the same item on case-insensitive
        // disks: it goes through a temporary name so a folder is never merged into itself.
        $caseOnly = mb_strtolower($target) === mb_strtolower($path);

        if ($this->exists($target) && ! $caseOnly) {
            throw ValidationException::withMessages(['name' => __('alfakit-file-manager::file-manager.errors.exists', ['name' => $name])]);
        }

        if ($caseOnly) {
            $temporary = $this->uniquePath(PathGuard::parent($path), (string) Str::ulid());
            $this->relocate($path, $temporary, keepSource: false);
            $path = $temporary;
        }

        $this->relocate($path, $target, keepSource: false);

        return $target;
    }

    /**
     * Move items into `$destination`, renaming them when the name is taken.
     *
     * @param  list<string>  $paths
     *
     * @throws ValidationException
     *
     * @return array<string, string> New paths by old path
     */
    public function move(array $paths, string $destination): array
    {
        return $this->transfer($paths, $destination, keepSource: false);
    }

    /**
     * Copy items into `$destination` (`name (1)` when the name is taken).
     *
     * @param  list<string>  $paths
     *
     * @throws ValidationException
     *
     * @return array<string, string> New paths by old path
     */
    public function copy(array $paths, string $destination): array
    {
        return $this->transfer($paths, $destination, keepSource: true);
    }

    /**
     * @param  list<string>  $paths
     *
     * @throws ValidationException
     *
     * @return array<string, string>
     */
    protected function transfer(array $paths, string $destination, bool $keepSource): array
    {
        $this->ensureFolder($destination, 'destination');

        foreach ($paths as $path) {
            $this->ensureExists($path, 'paths');

            if ($this->isFolder($path) && PathGuard::isWithin($destination, $path)) {
                throw ValidationException::withMessages(['destination' => __('alfakit-file-manager::file-manager.errors.into_itself', ['name' => PathGuard::basename($path)])]);
            }
        }

        $this->ensureWithinOperationLimit($paths);

        $moved = [];

        foreach ($paths as $path) {
            if (! $keepSource && PathGuard::parent($path) === $destination) {
                $moved[$path] = $path;

                continue;
            }

            $target = $this->uniquePath($destination, PathGuard::basename($path));
            $this->relocate($path, $target, $keepSource);
            $moved[$path] = $target;
        }

        return $moved;
    }

    /**
     * Folders count with every file inside them.
     *
     * @param  list<string>  $paths
     *
     * @throws ValidationException
     */
    public function ensureWithinOperationLimit(array $paths): void
    {
        $max = $this->plugin->getMaxItemsPerOperation();
        $count = 0;

        foreach ($paths as $path) {
            if (! $this->isFolder($path)) {
                $count++;

                continue;
            }

            foreach ($this->filesystem->allFiles($this->full($path)) as $ignored) {
                if (++$count > $max) {
                    break;
                }
            }

            if ($count > $max) {
                break;
            }
        }

        if ($count > $max) {
            throw ValidationException::withMessages(['paths' => __('alfakit-file-manager::file-manager.errors.too_many_items', ['max' => $max])]);
        }
    }

    /**
     * Move or copy a file or a whole folder to `$target` (a free path).
     */
    protected function relocate(string $path, string $target, bool $keepSource): void
    {
        $from = $this->full($path);
        $to = $this->full($target);

        if ($this->filesystem->fileExists($from)) {
            $keepSource ? $this->filesystem->copy($from, $to) : $this->filesystem->move($from, $to);

            return;
        }

        $this->filesystem->makeDirectory($to);

        foreach ($this->filesystem->allDirectories($from) as $directory) {
            $this->filesystem->makeDirectory($to.substr($directory, strlen($from)));
        }

        foreach ($this->filesystem->allFiles($from) as $file) {
            $destination = $to.substr($file, strlen($from));
            $keepSource ? $this->filesystem->copy($file, $destination) : $this->filesystem->move($file, $destination);
        }

        if (! $keepSource) {
            $this->filesystem->deleteDirectory($from);
        }
    }

    /**
     * `$name` in `$folder`, or `name (1).ext`, `name (2).ext`… when it is taken.
     */
    public function uniquePath(string $folder, string $name): string
    {
        $path = PathGuard::join($folder, $name);

        if (! $this->exists($path)) {
            return $path;
        }

        $extension = $this->isFolder($path) ? '' : FileTypes::extension($name);
        $base = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);

        for ($index = 1; $index < 10000; $index++) {
            $candidate = PathGuard::join($folder, $base.' ('.$index.')'.($extension === '' ? '' : '.'.$extension));

            if (! $this->exists($candidate)) {
                return $candidate;
            }
        }

        return PathGuard::join($folder, $base.' ('.Str::lower((string) Str::ulid()).')'.($extension === '' ? '' : '.'.$extension));
    }

    /**
     * Delete items for good.
     *
     * @param  list<string>  $paths
     */
    public function delete(array $paths): void
    {
        foreach ($paths as $path) {
            $full = $this->full($path);

            if ($this->filesystem->fileExists($full)) {
                $this->filesystem->delete($full);
            } elseif ($this->filesystem->directoryExists($full)) {
                $this->filesystem->deleteDirectory($full);
            }
        }
    }

    /**
     * Store a temporary upload in `$folder` (renamed when the name is taken), or over the
     * file at `$replace`.
     *
     * @return string The stored file's path
     */
    public function store(TemporaryUpload $upload, string $folder, string $name, ?string $replace = null): string
    {
        $target = $replace ?? $this->uniquePath($folder, $name);
        $stream = TemporaryUpload::disk()->readStream($upload->path());

        abort_unless(is_resource($stream), 422, 'The upload could not be read.');

        try {
            $this->filesystem->writeStream($this->full($target), $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $upload->delete();

        return $target;
    }

    // ---------------------------------------------------------------- Trash

    /**
     * Move items to the trash.
     *
     * @param  list<string>  $paths
     * @return list<string> The trash ids
     */
    public function trash(array $paths, ?string $deletedBy = null): array
    {
        $ids = [];

        foreach ($paths as $path) {
            $entry = $this->entry($path);

            if ($entry === null) {
                continue;
            }

            $id = (string) Str::ulid();
            $this->relocate($path, PathGuard::join(self::TRASH, $id, $entry['name']), keepSource: false);
            $this->filesystem->put($this->full(self::TRASH.'/'.$id.'.json'), json_encode([
                'path' => $path,
                'name' => $entry['name'],
                'type' => $entry['type'],
                'size' => $entry['size'],
                'deletedAt' => now()->toIso8601String(),
                'deletedBy' => $deletedBy,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The trashed items, most recently deleted first.
     *
     * @return list<array{id: string, path: string, name: string, type: string, size: int|null, deletedAt: string|null}>
     */
    public function trashed(): array
    {
        $items = [];
        $trash = $this->full(self::TRASH);

        if (! $this->filesystem->directoryExists($trash)) {
            return [];
        }

        foreach ($this->filesystem->files($trash) as $file) {
            $id = basename($file, '.json');

            if (! str_ends_with($file, '.json') || preg_match(self::TRASH_ID, $id) !== 1) {
                continue;
            }

            $meta = $this->readTrashMeta($id);

            if ($meta !== null) {
                $items[] = $meta;
            }
        }

        usort($items, static fn (array $a, array $b): int => strcmp($b['id'], $a['id']));

        return $items;
    }

    /**
     * @return array{id: string, path: string, name: string, type: string, size: int|null, deletedAt: string|null}|null
     */
    public function readTrashMeta(string $id): ?array
    {
        if (preg_match(self::TRASH_ID, $id) !== 1) {
            return null;
        }

        try {
            $meta = json_decode((string) $this->filesystem->get($this->full(self::TRASH.'/'.$id.'.json')), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($meta) || ! is_string($meta['name'] ?? null) || ! PathGuard::isSafeSegment($meta['name'])) {
            return null;
        }

        $path = PathGuard::normalize($meta['path'] ?? null);

        if ($path === null || $path === '') {
            return null;
        }

        return [
            'id' => $id,
            'path' => $path,
            'name' => $meta['name'],
            'type' => ($meta['type'] ?? 'file') === 'folder' ? 'folder' : 'file',
            'size' => is_int($meta['size'] ?? null) ? $meta['size'] : null,
            'deletedAt' => is_string($meta['deletedAt'] ?? null) ? $meta['deletedAt'] : null,
        ];
    }

    /**
     * Put trashed items back where they were (renamed when the name was taken since,
     * missing parent folders are created again).
     *
     * @param  list<string>  $ids
     * @return array<string, string> Restored paths by id
     */
    public function restore(array $ids): array
    {
        $restored = [];

        foreach ($ids as $id) {
            $meta = $this->readTrashMeta($id);

            if ($meta === null) {
                continue;
            }

            $parent = PathGuard::parent($meta['path']);

            if ($parent !== '' && ! $this->isFolder($parent)) {
                $this->filesystem->makeDirectory($this->full($parent));
            }

            $target = $this->uniquePath($parent, $meta['name']);
            $this->relocate(PathGuard::join(self::TRASH, $id, $meta['name']), $target, keepSource: false);
            $this->forgetTrashed($id);
            $restored[$id] = $target;
        }

        return $restored;
    }

    /**
     * Delete trashed items for good.
     *
     * @param  list<string>  $ids
     */
    public function purge(array $ids): void
    {
        foreach ($ids as $id) {
            if (preg_match(self::TRASH_ID, $id) === 1) {
                $this->forgetTrashed($id);
            }
        }
    }

    public function emptyTrash(): void
    {
        $this->filesystem->deleteDirectory($this->full(self::TRASH));
    }

    /**
     * Delete items trashed more than `$days` days ago.
     */
    public function pruneTrash(int $days): void
    {
        $limit = now()->subDays($days);

        foreach ($this->trashed() as $item) {
            $deletedAt = $item['deletedAt'] === null ? null : $this->safely(static fn (): Carbon => Carbon::parse($item['deletedAt']));

            if ($deletedAt instanceof Carbon && $deletedAt->lt($limit)) {
                $this->forgetTrashed($item['id']);
            }
        }
    }

    protected function forgetTrashed(string $id): void
    {
        $this->filesystem->deleteDirectory($this->full(self::TRASH.'/'.$id));
        $this->filesystem->delete($this->full(self::TRASH.'/'.$id.'.json'));
    }

    // ---------------------------------------------------------------- Internals

    /**
     * @return iterable<StorageAttributes>
     */
    protected function contents(string $path, bool $deep): iterable
    {
        if (! $this->filesystem instanceof FilesystemAdapter) {
            return [];
        }

        try {
            return $this->filesystem->getDriver()->listContents($this->full($path), $deep);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * A listed item as an entry, or null for hidden items (dot files, the trash) and anything
     * outside the root.
     *
     * @return array{path: string, name: string, type: string, size: int|null, modified: int|null}|null
     */
    protected function toEntry(StorageAttributes $attributes): ?array
    {
        $relative = $this->relative($attributes->path());

        if ($relative === null || $relative === '') {
            return null;
        }

        foreach (explode('/', $relative) as $segment) {
            if (! PathGuard::isSafeSegment($segment)) {
                return null;
            }
        }

        $isFile = $attributes instanceof FileAttributes;

        return [
            'path' => $relative,
            'name' => PathGuard::basename($relative),
            'type' => $isFile ? 'file' : 'folder',
            'size' => $isFile ? $this->safely(static fn (): ?int => $attributes->fileSize()) : null,
            'modified' => $this->safely(static fn (): ?int => $attributes->lastModified()),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    protected function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
