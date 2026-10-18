<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Exceptions\ActionNotResolvableException;
use AlfaSix\Alfakit\Http\Controllers\Concerns\PullsFlashedNotifications;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use AlfaSix\AlfakitFileManager\FileManagerPlugin;
use AlfaSix\AlfakitFileManager\Http\Controllers\FileController;
use AlfaSix\AlfakitFileManager\Models\FileManagerLink;
use AlfaSix\AlfakitFileManager\Support\Drive;
use AlfaSix\AlfakitFileManager\Support\FileTypes;
use AlfaSix\AlfakitFileManager\Support\PathGuard;
use AlfaSix\AlfakitFileManager\Support\SignedFiles;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Validation\ValidationException;

/**
 * The file manager: one page with the `file-manager` component, whose JSON actions (run as
 * the `file-manager` component of the page) list, search, upload, organise, preview, download
 * and share files.
 *
 * Every action re-checks the plugin's authorisation and the operation's ability, normalises
 * every posted path with `PathGuard` and works through a `Drive` bound to the disk's root
 * (the tenant's folder on tenancy panels), so a request can never reach another root.
 *
 * Extend it and pass your class to `FileManagerPlugin::make()->usingPage(...)` to change it.
 */
class FileManager extends Page
{
    use PullsFlashedNotifications;

    public const COMPONENT = 'file-manager';

    /** The JSON actions of the component, by role. */
    public const ACTIONS = [
        'list' => 'list',
        'tree' => 'tree',
        'search' => 'search',
        'createFolder' => 'createFolder',
        'rename' => 'rename',
        'move' => 'move',
        'copy' => 'copy',
        'delete' => 'delete',
        'restore' => 'restore',
        'emptyTrash' => 'emptyTrash',
        'upload' => 'upload',
        'replace' => 'replace',
        'details' => 'details',
        'preview' => 'preview',
        'download' => 'download',
        'zip' => 'zip',
        'share' => 'share',
        'links' => 'links',
        'revoke' => 'revoke',
    ];

    protected static ?string $slug = 'files';

    protected static bool $isDiscovered = false;

    protected static Width|string|null $maxContentWidth = Width::Full;

    /** The folder the page opens in (`?disk=…&path=…`). */
    protected ?string $initialDisk = null;

    protected string $initialPath = '';

    public static function getPlugin(): FileManagerPlugin
    {
        return FileManagerPlugin::get();
    }

    public static function canAccess(): bool
    {
        return static::getPlugin()->isAuthorized();
    }

    public function getTitle(): string
    {
        return (string) __('alfakit-file-manager::file-manager.page.title');
    }

    public static function getNavigationLabel(): string
    {
        return static::getPlugin()->getNavigationLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()->getNavigationGroup();
    }

    public static function getNavigationIcon(): ?string
    {
        return static::getPlugin()->getNavigationIcon();
    }

    public static function getNavigationSort(): ?int
    {
        return static::getPlugin()->getNavigationSort();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::getPlugin()->shouldRegisterNavigation();
    }

    /**
     * The page, plus `{page}/file` (a file, inline or as a download) and `{page}/zip`
     * (several items as one archive): signed routes inside the panel, so only signed-in users
     * of the tenant the link was made for can open them.
     */
    public static function registerRoutes(Panel $panel): void
    {
        parent::registerRoutes($panel);

        $base = static::getClusterRoutePrefix().ltrim(static::getRoutePath(), '/');

        Route::get($base.'/file', [FileController::class, 'show'])
            ->name(static::getRelativeRouteName().'.file')
            ->middleware('signed');

        Route::get($base.'/zip', [FileController::class, 'zip'])
            ->name(static::getRelativeRouteName().'.zip')
            ->middleware('signed');
    }

    public function mount(mixed ...$parameters): void
    {
        $plugin = static::getPlugin();
        $disk = request()->query('disk');
        $this->initialDisk = $plugin->hasDisk($disk) ? (string) $disk : null;
        $path = PathGuard::normalize(request()->query('path'));
        $this->initialPath = $path ?? '';
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->initialDisk = null;
        $this->initialPath = '';
    }

    public function drive(?string $disk = null): Drive
    {
        $plugin = static::getPlugin();

        return new Drive($plugin, $disk ?? $plugin->getDefaultDisk());
    }

    // ---------------------------------------------------------------- Component

    protected function getContentBlocks(): array
    {
        return [
            ['type' => 'Schema', 'key' => self::COMPONENT, 'components' => [[...$this->serializeManager(), 'columnSpan' => ['default' => 'full']]]],
            ...parent::getContentBlocks(),
        ];
    }

    /**
     * The `file-manager` component. The picker (`FileManagerPicker`) uses it without a
     * listing: it loads the folder when its dialog opens.
     *
     * @param  array<string, mixed>  $picker  `multiple`, `accept`, `disk`, `directory`
     * @return array<string, mixed>
     */
    public function serializeManager(string $mode = 'manager', array $picker = []): array
    {
        $plugin = static::getPlugin();
        $disk = $mode === 'picker' ? (is_string($picker['disk'] ?? null) ? $picker['disk'] : $plugin->getDefaultDisk()) : ($this->initialDisk ?? $plugin->getDefaultDisk());
        $drive = $this->drive($disk);
        $path = $mode === 'picker' ? (PathGuard::normalize($picker['directory'] ?? null) ?? '') : $this->initialPath;

        if (! $drive->isFolder($path)) {
            $path = '';
        }

        $permissions = [];

        foreach (FileManagerPlugin::ABILITIES as $ability) {
            $permissions[$ability] = $plugin->allows($ability, $disk);
        }

        if (! $plugin->hasZipDownloads()) {
            $permissions['zip'] = false;
        } else {
            $permissions['zip'] = $permissions['download'];
        }

        $accept = $mode === 'picker' && is_array($picker['accept'] ?? null) ? array_values(array_filter($picker['accept'], is_string(...))) : null;

        return array_filter([
            'type' => 'file-manager',
            'key' => self::COMPONENT,
            'actionComponent' => self::COMPONENT,
            'token' => $mode === 'picker' ? $this->getPickerToken() : null,
            'mode' => $mode,
            'actions' => self::ACTIONS,
            'disks' => array_map(static fn (string $name, string $label): array => ['name' => $name, 'label' => $label], array_keys($plugin->getDisks()), $plugin->getDisks()),
            'disk' => $disk,
            'path' => $path,
            'listing' => $mode === 'picker' ? null : $this->listing($drive, $path),
            'tree' => $mode === 'picker' ? null : $this->serializeFolders($drive->folders('')),
            'permissions' => $permissions,
            'readOnly' => $plugin->isReadOnly() ?: null,
            'upload' => $permissions['upload'] ? array_filter([
                'maxSize' => $plugin->getMaxUploadSize() * 1024,
                'accept' => $plugin->getAcceptedFileTypes(),
                'parallel' => 3,
                'policy' => \AlfaSix\Alfakit\Support\UploadPolicy::token($plugin->getMaxUploadSize(), $plugin->getAcceptedFileTypes() ?: null),
            ], static fn (mixed $value): bool => $value !== null) : null,
            'zip' => $permissions['zip'] ? ['maxSize' => $plugin->getMaxZipSize() * 1024, 'maxFiles' => $plugin->getMaxZipFiles()] : null,
            'search' => ['recursive' => $plugin->hasRecursiveSearch()],
            'trash' => $plugin->hasTrash() ?: null,
            'share' => $permissions['share'] ? array_filter([
                'expiries' => $plugin->getShareLinkExpiries(),
                'publicLinks' => $plugin->hasPublicLinks() ?: null,
            ], static fn (mixed $value): bool => $value !== null) : null,
            'view' => $plugin->getDefaultView(),
            'maxItems' => $plugin->getMaxItemsPerOperation(),
            'multiple' => $mode === 'picker' ? (bool) ($picker['multiple'] ?? false) : null,
            'accept' => $accept,
            'syncUrl' => $mode === 'manager' ? true : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * A context token for this page's `file-manager` component, for pickers on other pages.
     */
    public function getPickerToken(): string
    {
        $panel = $this->getPanel();
        $parameters = [];

        if ($panel->hasTenancy() && ($tenant = alfakit()->getTenant()) !== null) {
            $parameters['tenant'] = $panel->getTenantRouteKey($tenant);
        }

        return (new SchemaContext($panel->getId(), static::class, $parameters, self::COMPONENT))->toToken();
    }

    // ---------------------------------------------------------------- Actions

    /**
     * The component's actions resolve here; everything else as usual.
     */
    public function resolveAction(?string $component, string $name, bool $isBulk = false): Action
    {
        if ($component !== self::COMPONENT) {
            return parent::resolveAction($component, $name, $isBulk);
        }

        $handler = match ($name) {
            'list' => $this->listResponse(...),
            'tree' => $this->treeResponse(...),
            'search' => $this->searchResponse(...),
            'createFolder' => $this->createFolderResponse(...),
            'rename' => $this->renameResponse(...),
            'move' => fn (array $arguments): array => $this->transferResponse($arguments, copy: false),
            'copy' => fn (array $arguments): array => $this->transferResponse($arguments, copy: true),
            'delete' => $this->deleteResponse(...),
            'restore' => $this->restoreResponse(...),
            'emptyTrash' => $this->emptyTrashResponse(...),
            'upload' => $this->uploadResponse(...),
            'replace' => $this->replaceResponse(...),
            'details' => $this->detailsResponse(...),
            'preview' => $this->previewResponse(...),
            'download' => $this->downloadResponse(...),
            'zip' => $this->zipResponse(...),
            'share' => $this->shareResponse(...),
            'links' => $this->linksResponse(...),
            'revoke' => $this->revokeResponse(...),
            default => null,
        };

        if ($handler === null || $isBulk) {
            throw new ActionNotResolvableException("Action [{$name}] could not be resolved on [".static::class.'] for the file manager.');
        }

        return $this->makeManagerAction($name, $handler);
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $handler
     */
    protected function makeManagerAction(string $name, Closure $handler): Action
    {
        return Action::make($name)
            ->context($this)
            ->authorize(static fn (): bool => static::getPlugin()->isAuthorized())
            ->action(fn (array $arguments): array => [
                ...$handler($arguments),
                'notifications' => $this->pullFlashedNotifications(request()),
            ]);
    }

    /**
     * The posted disk's drive.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ValidationException
     */
    protected function driveFor(array $arguments): Drive
    {
        $disk = $arguments['disk'] ?? null;

        if (! static::getPlugin()->hasDisk($disk)) {
            throw ValidationException::withMessages(['disk' => __('alfakit-file-manager::file-manager.errors.unknown_disk')]);
        }

        return $this->drive((string) $disk);
    }

    /**
     * @param  list<string>  $paths
     */
    protected function authorizeAbility(string $ability, Drive $drive, string $path = '', array $paths = []): void
    {
        abort_unless(static::getPlugin()->allows($ability, $drive->disk(), $path, $paths), 403);
    }

    /**
     * The folder the user is looking at, refreshed after a change.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function currentListing(Drive $drive, array $arguments): array
    {
        if (($arguments['trash'] ?? false) === true && static::getPlugin()->hasTrash()) {
            return $this->trashListing($drive);
        }

        $current = PathGuard::normalize($arguments['current'] ?? null) ?? '';

        return $this->listing($drive, $drive->isFolder($current) ? $current : '');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function listResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);

        if (($arguments['trash'] ?? false) === true) {
            abort_unless(static::getPlugin()->hasTrash(), 404);

            return ['listing' => $this->trashListing($drive)];
        }

        $path = PathGuard::path($arguments['path'] ?? '');
        $drive->ensureFolder($path);

        return ['listing' => $this->listing($drive, $path, $this->pickerOptions($arguments))];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function treeResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        $path = PathGuard::path($arguments['path'] ?? '');
        $drive->ensureFolder($path);

        return ['path' => $path, 'nodes' => $this->serializeFolders($drive->folders($path))];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function searchResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        $path = PathGuard::path($arguments['path'] ?? '');
        $drive->ensureFolder($path);
        $query = is_string($arguments['query'] ?? null) ? mb_substr(trim($arguments['query']), 0, 100) : '';
        $recursive = ($arguments['recursive'] ?? false) === true && static::getPlugin()->hasRecursiveSearch();

        if ($query === '') {
            return ['entries' => [], 'truncated' => false, 'query' => ''];
        }

        $result = $drive->search($path, $query, $recursive);
        $picker = $this->pickerOptions($arguments);

        return [
            'query' => $query,
            'entries' => array_map(fn (array $entry): array => $this->serializeEntry($drive, $entry, $picker), $result['entries']),
            'truncated' => $result['truncated'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function createFolderResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        $parent = PathGuard::path($arguments['path'] ?? '');
        $name = PathGuard::name($arguments['name'] ?? null);
        $this->authorizeAbility('createFolder', $drive, $parent);

        $path = $drive->createFolder($parent, $name);

        Notification::make()->success()->title(__('alfakit-file-manager::file-manager.notifications.folder_created', ['name' => $name]))->send();

        return ['path' => $path, 'listing' => $this->currentListing($drive, $arguments), 'nodes' => $this->serializeFolders($drive->folders($parent)), 'parent' => $parent];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function renameResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        $path = PathGuard::path($arguments['path'] ?? null);
        $name = PathGuard::name($arguments['name'] ?? null);

        if ($path === '') {
            throw ValidationException::withMessages(['path' => __('alfakit-file-manager::file-manager.errors.invalid_path')]);
        }

        $this->authorizeAbility('rename', $drive, $path);

        if ($drive->isFile($path)) {
            $this->ensureNameIsAllowed($name, $drive->mimeType($path));
        }

        $target = $drive->rename($path, $name);
        $this->moveLinks($drive, [$path => $target]);

        Notification::make()->success()->title(__('alfakit-file-manager::file-manager.notifications.renamed', ['name' => $name]))->send();

        return ['path' => $target, 'listing' => $this->currentListing($drive, $arguments)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function transferResponse(array $arguments, bool $copy): array
    {
        $drive = $this->driveFor($arguments);
        $paths = PathGuard::paths($arguments['paths'] ?? null, static::getPlugin()->getMaxItemsPerOperation());
        $destination = PathGuard::path($arguments['destination'] ?? '', 'destination');
        $this->authorizeAbility($copy ? 'copy' : 'move', $drive, $destination, $paths);

        $result = $copy ? $drive->copy($paths, $destination) : $drive->move($paths, $destination);

        if (! $copy) {
            $this->moveLinks($drive, $result);
        }

        $count = count($result);

        Notification::make()->success()->title(trans_choice('alfakit-file-manager::file-manager.notifications.'.($copy ? 'copied' : 'moved'), $count, ['count' => $count]))->send();

        return ['paths' => array_values($result), 'listing' => $this->currentListing($drive, $arguments)];
    }

    /**
     * Trash items (or delete them for good: `permanent`, without a trash, or in the trash).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function deleteResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        $plugin = static::getPlugin();

        if (($arguments['trash'] ?? false) === true) {
            abort_unless($plugin->hasTrash(), 404);
            $ids = $this->trashIds($arguments['paths'] ?? null);
            $this->authorizeAbility('delete', $drive, '', $ids);
            $drive->purge($ids);

            Notification::make()->success()->title(trans_choice('alfakit-file-manager::file-manager.notifications.deleted', count($ids), ['count' => count($ids)]))->send();

            return ['listing' => $this->trashListing($drive)];
        }

        $paths = PathGuard::paths($arguments['paths'] ?? null, $plugin->getMaxItemsPerOperation());
        $this->authorizeAbility('delete', $drive, '', $paths);

        foreach ($paths as $path) {
            $drive->ensureExists($path, 'paths');
        }

        $permanent = ! $plugin->hasTrash() || ($arguments['permanent'] ?? false) === true;

        if ($permanent) {
            $drive->delete($paths);
            $this->deleteLinks($drive, $paths);
        } else {
            $drive->ensureWithinOperationLimit($paths);
            $drive->trash($paths, SchemaContext::currentUser());
            $this->deleteLinks($drive, $paths);
        }

        Notification::make()->success()->title(trans_choice('alfakit-file-manager::file-manager.notifications.'.($permanent ? 'deleted' : 'trashed'), count($paths), ['count' => count($paths)]))->send();

        return ['listing' => $this->currentListing($drive, $arguments)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function restoreResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        abort_unless(static::getPlugin()->hasTrash(), 404);
        $ids = $this->trashIds($arguments['paths'] ?? null);
        $this->authorizeAbility('restore', $drive, '', $ids);

        $restored = $drive->restore($ids);

        Notification::make()->success()->title(trans_choice('alfakit-file-manager::file-manager.notifications.restored', count($restored), ['count' => count($restored)]))->send();

        return ['paths' => array_values($restored), 'listing' => $this->trashListing($drive)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function emptyTrashResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        abort_unless(static::getPlugin()->hasTrash(), 404);
        $this->authorizeAbility('delete', $drive);

        $drive->emptyTrash();

        Notification::make()->success()->title(__('alfakit-file-manager::file-manager.notifications.trash_emptied'))->send();

        return ['listing' => $this->trashListing($drive)];
    }

    /**
     * Store a temporary upload (made by the panel's upload endpoint) in a folder.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function uploadResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        $folder = PathGuard::path($arguments['path'] ?? '');
        $this->authorizeAbility('upload', $drive, $folder);
        $drive->ensureFolder($folder);

        $upload = $this->resolveUpload($arguments['key'] ?? null);
        $name = $this->uploadName($upload);
        $this->ensureUploadIsAllowed($upload, $name);

        $path = $drive->store($upload, $folder, $name);
        $entry = $drive->entry($path);

        return [
            'path' => $path,
            'entry' => $entry === null ? null : $this->serializeEntry($drive, $entry),
            'listing' => ($arguments['refresh'] ?? true) === false ? null : $this->currentListing($drive, $arguments),
        ];
    }

    /**
     * Overwrite a file with a temporary upload (an image saved from the image editor).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function replaceResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        $path = PathGuard::path($arguments['path'] ?? null);
        $this->authorizeAbility('upload', $drive, $path);

        if (! $drive->isFile($path)) {
            throw ValidationException::withMessages(['path' => __('alfakit-file-manager::file-manager.errors.not_found')]);
        }

        $upload = $this->resolveUpload($arguments['key'] ?? null);
        $this->ensureUploadIsAllowed($upload, PathGuard::basename($path));
        $drive->store($upload, PathGuard::parent($path), PathGuard::basename($path), replace: $path);

        Notification::make()->success()->title(__('alfakit-file-manager::file-manager.notifications.saved', ['name' => PathGuard::basename($path)]))->send();

        return ['path' => $path, 'listing' => $this->currentListing($drive, $arguments)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function detailsResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        $path = PathGuard::path($arguments['path'] ?? null);
        $entry = $drive->entry($path);

        if ($entry === null) {
            throw ValidationException::withMessages(['path' => __('alfakit-file-manager::file-manager.errors.not_found')]);
        }

        $details = $this->serializeEntry($drive, $entry);

        if ($entry['type'] === 'file') {
            $mime = $drive->mimeType($path);
            $details['mime'] = $mime;
            $details['kind'] = FileTypes::kind($mime, $entry['name']);

            if ($details['kind'] === 'image' && ($dimensions = $drive->imageDimensions($path)) !== null) {
                $details['width'] = $dimensions[0];
                $details['height'] = $dimensions[1];
            }

            if (static::getPlugin()->hasPublicLinks() && $this->linksTableExists()) {
                $details['links'] = FileManagerLink::query()->forRoot($this->getPanel()->getId(), $drive->disk(), $drive->root())->where('path', $path)->count();
            }
        } else {
            $listing = $drive->list($path);
            $details['items'] = count($listing['entries']);
            $details['itemsTruncated'] = $listing['truncated'] ?: null;
        }

        $details['location'] = PathGuard::parent($path);

        return ['details' => array_filter($details, static fn (mixed $value): bool => $value !== null)];
    }

    /**
     * What the preview dialog shows: an inline URL for images, PDFs and players, the text of
     * text files (up to `textPreviewMaxSize()`).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function previewResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        $path = PathGuard::path($arguments['path'] ?? null);

        if (! $drive->isFile($path)) {
            throw ValidationException::withMessages(['path' => __('alfakit-file-manager::file-manager.errors.not_found')]);
        }

        $mime = $drive->mimeType($path);
        $kind = FileTypes::kind($mime, $path);
        $plugin = static::getPlugin();

        if (! FileTypes::isPreviewable($kind, $mime)) {
            return ['preview' => ['path' => $path, 'kind' => $kind, 'mime' => $mime, 'previewable' => false]];
        }

        $preview = ['path' => $path, 'kind' => $kind, 'mime' => $mime, 'previewable' => true];

        if ($kind === 'text') {
            $max = $plugin->getTextPreviewMaxSize() * 1024;
            $size = $drive->entry($path)['size'] ?? 0;
            $text = $drive->readStart($path, $max);

            if (str_contains($text, "\0")) {
                return ['preview' => [...$preview, 'previewable' => false]];
            }

            if (! mb_check_encoding($text, 'UTF-8')) {
                // A cut through a multi-byte character, or another encoding.
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }

            $preview['text'] = $text;
            $preview['truncated'] = $size > $max ?: null;
        } else {
            $preview['url'] = SignedFiles::fileUrl($this, $drive->disk(), $path, SignedFiles::INLINE);
        }

        if ($kind === 'image' && $plugin->allows('upload', $drive->disk(), $path)) {
            $preview['editable'] = true;
        }

        if ($plugin->allows('download', $drive->disk(), $path)) {
            $preview['downloadUrl'] = SignedFiles::fileUrl($this, $drive->disk(), $path, SignedFiles::DOWNLOAD);
        }

        return ['preview' => array_filter($preview, static fn (mixed $value): bool => $value !== null)];
    }

    /**
     * A signed download link of one file.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function downloadResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        $path = PathGuard::path($arguments['path'] ?? null);
        $this->authorizeAbility('download', $drive, $path);

        if (! $drive->isFile($path)) {
            throw ValidationException::withMessages(['path' => __('alfakit-file-manager::file-manager.errors.not_found')]);
        }

        return ['url' => SignedFiles::fileUrl($this, $drive->disk(), $path, SignedFiles::DOWNLOAD)];
    }

    /**
     * A signed link that downloads the selected files and folders as one zip, after checking
     * the archive stays within the size and file limits.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function zipResponse(array $arguments): array
    {
        $plugin = static::getPlugin();
        abort_unless($plugin->hasZipDownloads(), 404);

        $drive = $this->driveFor($arguments);
        $paths = PathGuard::paths($arguments['paths'] ?? null, $plugin->getMaxZipFiles());
        $this->authorizeAbility('download', $drive, '', $paths);

        foreach ($paths as $path) {
            $drive->ensureExists($path, 'paths');
        }

        $key = 'alfakit-file-manager-zip:'.sha1($this->getPanel()->getId().'|'.(SchemaContext::currentUser() ?? request()->ip()));

        if (RateLimiter::tooManyAttempts($key, $plugin->getZipRateLimit())) {
            throw ValidationException::withMessages(['paths' => __('alfakit-file-manager::file-manager.errors.rate_limited', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        RateLimiter::hit($key, 60);

        \AlfaSix\AlfakitFileManager\Support\ZipArchiver::plan($drive, $paths, $plugin);

        return ['url' => SignedFiles::zipUrl($this, $drive->disk(), $paths)];
    }

    /**
     * A link anyone can open: a signed temporary URL, or a stored (revocable) public link.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function shareResponse(array $arguments): array
    {
        $plugin = static::getPlugin();
        $drive = $this->driveFor($arguments);
        $path = PathGuard::path($arguments['path'] ?? null);
        $this->authorizeAbility('share', $drive, $path);

        if (! $drive->isFile($path)) {
            throw ValidationException::withMessages(['path' => __('alfakit-file-manager::file-manager.errors.not_found')]);
        }

        $expiry = $arguments['expiry'] ?? null;
        $public = $plugin->hasPublicLinks() && $this->linksTableExists();

        if (! (is_int($expiry) && in_array($expiry, $plugin->getShareLinkExpiries(), true)) && ! ($expiry === null && $public)) {
            throw ValidationException::withMessages(['expiry' => __('alfakit-file-manager::file-manager.errors.invalid_expiry')]);
        }

        $expiresAt = $expiry === null ? null : now()->addMinutes($expiry);

        if ($public) {
            [$link, $token] = FileManagerLink::issue([
                'panel' => $this->getPanel()->getId(),
                'disk' => $drive->disk(),
                'root' => $drive->root(),
                'path' => $path,
                'created_by' => SchemaContext::currentUser(),
                'expires_at' => $expiresAt,
            ]);

            return ['url' => SignedFiles::publicLinkUrl($token), 'expiresAt' => $expiresAt?->toIso8601String(), 'link' => $this->serializeLink($link)];
        }

        abort_if($expiresAt === null, 422);

        return ['url' => SignedFiles::shareUrl($this->getPanel()->getId(), $drive->disk(), $drive->root(), $path, $expiresAt), 'expiresAt' => $expiresAt->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function linksResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        $path = PathGuard::path($arguments['path'] ?? null);
        $this->authorizeAbility('share', $drive, $path);

        if (! static::getPlugin()->hasPublicLinks() || ! $this->linksTableExists()) {
            return ['links' => []];
        }

        $links = FileManagerLink::query()->forRoot($this->getPanel()->getId(), $drive->disk(), $drive->root())->where('path', $path)->latest('id')->limit(50)->get();

        return ['links' => $links->map(fn (FileManagerLink $link): array => $this->serializeLink($link))->all()];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function revokeResponse(array $arguments): array
    {
        $drive = $this->driveFor($arguments);
        abort_unless(static::getPlugin()->hasPublicLinks() && $this->linksTableExists(), 404);
        $id = $arguments['id'] ?? null;
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id)), 404);

        $link = FileManagerLink::query()->forRoot($this->getPanel()->getId(), $drive->disk(), $drive->root())->whereKey((int) $id)->first();
        abort_if($link === null, 404);
        $this->authorizeAbility('share', $drive, $link->path);

        $link->delete();

        Notification::make()->success()->title(__('alfakit-file-manager::file-manager.notifications.link_revoked'))->send();

        return ['ok' => true];
    }

    // ---------------------------------------------------------------- Uploads

    /**
     * @throws ValidationException
     */
    protected function resolveUpload(mixed $key): TemporaryUpload
    {
        $upload = is_string($key) ? TemporaryUpload::find($key) : null;

        if ($upload === null) {
            throw ValidationException::withMessages(['key' => __('alfakit-file-manager::file-manager.errors.upload_missing')]);
        }

        return $upload;
    }

    /**
     * The upload's own name, made safe (forbidden characters replaced, no leading dots).
     */
    protected function uploadName(TemporaryUpload $upload): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\\\\\/:*?"<>|]+/u', '-', $upload->originalName()) ?? '';
        $name = ltrim(trim($name), '.');
        $name = rtrim($name, '. ');

        if ($name === '' || ! PathGuard::isSafeSegment($name)) {
            $name = 'file'.($upload->extension() === '' ? '' : '.'.$upload->extension());
        }

        if (strlen($name) > PathGuard::MAX_NAME_BYTES) {
            $extension = FileTypes::extension($name);
            $name = mb_strcut($name, 0, PathGuard::MAX_NAME_BYTES - strlen($extension) - 1).($extension === '' ? '' : '.'.$extension);
        }

        return $name;
    }

    /**
     * Size, blocked types, the detected type and the accepted types.
     *
     * @throws ValidationException
     */
    protected function ensureUploadIsAllowed(TemporaryUpload $upload, string $name): void
    {
        $plugin = static::getPlugin();
        $max = $plugin->getMaxUploadSize();

        if ($upload->size() > $max * 1024) {
            $upload->delete();

            throw ValidationException::withMessages(['key' => __('alfakit-file-manager::file-manager.errors.too_large', ['name' => $name, 'max' => \Illuminate\Support\Number::fileSize($max * 1024)])]);
        }

        // The type of the contents, detected again here (never the browser's).
        $stream = TemporaryUpload::disk()->readStream($upload->path());
        $mime = FileTypes::detect($stream) ?? $upload->mimeType();

        if (is_resource($stream)) {
            fclose($stream);
        }

        $reason = FileTypes::rejection($name, $mime, $plugin->getBlockedExtensions(), $plugin->getBlockedMimeTypes(), $plugin->getAcceptedFileTypes());

        if ($reason !== null) {
            $upload->delete();

            throw ValidationException::withMessages(['key' => __('alfakit-file-manager::file-manager.errors.'.$reason, ['name' => $name])]);
        }
    }

    /**
     * A rename may not give a file a blocked extension (`notes.txt` → `notes.php`).
     *
     * @throws ValidationException
     */
    protected function ensureNameIsAllowed(string $name, ?string $mime): void
    {
        $plugin = static::getPlugin();
        $reason = FileTypes::rejection($name, $mime, $plugin->getBlockedExtensions(), $plugin->getBlockedMimeTypes(), null);

        if ($reason !== null) {
            throw ValidationException::withMessages(['name' => __('alfakit-file-manager::file-manager.errors.'.$reason, ['name' => $name])]);
        }
    }

    // ---------------------------------------------------------------- Serialisation

    /**
     * A folder's entries with its breadcrumbs.
     *
     * @param  array{accept?: list<string>|null, picker?: bool}  $picker
     * @return array<string, mixed>
     */
    public function listing(Drive $drive, string $path, array $picker = []): array
    {
        $result = $drive->list($path);
        $breadcrumbs = [];
        $current = '';

        foreach ($path === '' ? [] : explode('/', $path) as $segment) {
            $current = PathGuard::join($current, $segment);
            $breadcrumbs[] = ['path' => $current, 'name' => $segment];
        }

        return array_filter([
            'disk' => $drive->disk(),
            'path' => $path,
            'breadcrumbs' => $breadcrumbs,
            'entries' => array_map(fn (array $entry): array => $this->serializeEntry($drive, $entry, $picker), $result['entries']),
            'truncated' => $result['truncated'] ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The trash as a listing: entries are addressed by their trash id.
     *
     * @return array<string, mixed>
     */
    public function trashListing(Drive $drive): array
    {
        $days = static::getPlugin()->getTrashRetentionDays();

        if ($days !== null) {
            $drive->pruneTrash($days);
        }

        return [
            'disk' => $drive->disk(),
            'path' => '',
            'trash' => true,
            'breadcrumbs' => [],
            'retentionDays' => $days,
            'entries' => array_map(static fn (array $item): array => array_filter([
                'path' => $item['id'],
                'name' => $item['name'],
                'type' => $item['type'],
                'size' => $item['size'],
                'kind' => $item['type'] === 'folder' ? 'folder' : FileTypes::kind(FileTypes::guessMimeType($item['name']), $item['name']),
                'extension' => $item['type'] === 'folder' ? null : (FileTypes::extension($item['name']) ?: null),
                'modified' => $item['deletedAt'],
                'trashed' => ['originalPath' => $item['path'], 'deletedAt' => $item['deletedAt']],
            ], static fn (mixed $value): bool => $value !== null), $drive->trashed()),
        ];
    }

    /**
     * @param  array{path: string, name: string, type: string, size: int|null, modified: int|null}  $entry
     * @param  array{accept?: list<string>|null, picker?: bool}  $picker
     * @return array<string, mixed>
     */
    public function serializeEntry(Drive $drive, array $entry, array $picker = []): array
    {
        $isFile = $entry['type'] === 'file';
        $mime = $isFile ? FileTypes::guessMimeType($entry['name']) : null;
        $kind = $isFile ? FileTypes::kind($mime, $entry['name']) : 'folder';
        $serialized = [
            'path' => $entry['path'],
            'name' => $entry['name'],
            'type' => $entry['type'],
            'size' => $entry['size'],
            'mime' => $mime,
            'extension' => $isFile ? (FileTypes::extension($entry['name']) ?: null) : null,
            'kind' => $kind,
            'modified' => $entry['modified'] === null ? null : Carbon::createFromTimestamp($entry['modified'])->toIso8601String(),
        ];

        // Thumbnails of images the browser draws, up to 15 MB.
        if ($kind === 'image' && FileTypes::isPreviewable($kind, $mime) && ($entry['size'] ?? 0) <= 15 * 1024 * 1024) {
            $serialized['thumbnail'] = SignedFiles::fileUrl($this, $drive->disk(), $entry['path'], SignedFiles::INLINE);
        }

        if (($picker['picker'] ?? false) === true) {
            $serialized['value'] = $isFile ? $drive->full($entry['path']) : null;
            $accept = $picker['accept'] ?? null;
            $serialized['selectable'] = $isFile && ($accept === null || $accept === [] || FileTypes::matches($accept, $mime, $entry['name']));
        }

        return array_filter($serialized, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<array{path: string, name: string, hasChildren: bool}>  $folders
     * @return list<array<string, mixed>>
     */
    protected function serializeFolders(array $folders): array
    {
        return array_map(static fn (array $folder): array => ['path' => $folder['path'], 'name' => $folder['name'], 'hasChildren' => $folder['hasChildren']], $folders);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeLink(FileManagerLink $link): array
    {
        $token = $link->plainToken();

        return array_filter([
            'id' => $link->id,
            'url' => $token === null ? null : SignedFiles::publicLinkUrl($token),
            'expiresAt' => $link->expires_at?->toIso8601String(),
            'expired' => $link->isExpired() ?: null,
            'createdAt' => $link->created_at?->toIso8601String(),
            'downloads' => $link->downloads,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{accept?: list<string>|null, picker?: bool}
     */
    protected function pickerOptions(array $arguments): array
    {
        if (($arguments['picker'] ?? false) !== true) {
            return [];
        }

        $accept = is_array($arguments['accept'] ?? null) ? array_values(array_filter($arguments['accept'], is_string(...))) : null;

        return ['picker' => true, 'accept' => $accept];
    }

    /**
     * @throws ValidationException
     *
     * @return list<string>
     */
    protected function trashIds(mixed $ids): array
    {
        if (! is_array($ids) || $ids === [] || count($ids) > static::getPlugin()->getMaxItemsPerOperation()) {
            throw ValidationException::withMessages(['paths' => __('alfakit-file-manager::file-manager.errors.nothing_selected')]);
        }

        $valid = [];

        foreach ($ids as $id) {
            if (! is_string($id) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) {
                throw ValidationException::withMessages(['paths' => __('alfakit-file-manager::file-manager.errors.invalid_path')]);
            }

            $valid[$id] = true;
        }

        return array_keys($valid);
    }

    // ---------------------------------------------------------------- Public links

    protected function linksTableExists(): bool
    {
        return DatabaseSchema::connection((new FileManagerLink)->getConnectionName())->hasTable('alfakit_file_manager_links');
    }

    /**
     * Links follow renamed and moved files (and the files inside moved folders).
     *
     * @param  array<string, string>  $moves
     */
    protected function moveLinks(Drive $drive, array $moves): void
    {
        if (! static::getPlugin()->hasPublicLinks() || ! $this->linksTableExists()) {
            return;
        }

        foreach ($moves as $from => $to) {
            if ($from === $to) {
                continue;
            }

            foreach ($this->linksUnder($drive, $from) as $link) {
                $link->path = $to.substr($link->path, strlen($from));
                $link->save();
            }
        }
    }

    /**
     * Links of deleted files (and of files inside deleted folders) stop working.
     *
     * @param  list<string>  $paths
     */
    protected function deleteLinks(Drive $drive, array $paths): void
    {
        if (! static::getPlugin()->hasPublicLinks() || ! $this->linksTableExists()) {
            return;
        }

        foreach ($paths as $path) {
            foreach ($this->linksUnder($drive, $path) as $link) {
                $link->delete();
            }
        }
    }

    /**
     * The links of a file, or of every file in a folder.
     *
     * @return list<FileManagerLink>
     */
    protected function linksUnder(Drive $drive, string $path): array
    {
        // `LIKE` narrows the query; the exact prefix check runs here (`%` and `_` in names).
        return FileManagerLink::query()->forRoot($this->getPanel()->getId(), $drive->disk(), $drive->root())
            ->where(static fn ($query) => $query->where('path', $path)->orWhere('path', 'like', $path.'/%'))
            ->get()
            ->filter(static fn (FileManagerLink $link): bool => $link->path === $path || str_starts_with($link->path, $path.'/'))
            ->values()
            ->all();
    }
}
