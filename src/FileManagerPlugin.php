<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\AlfakitFileManager\Pages\FileManager;
use AlfaSix\AlfakitFileManager\Support\FileTypes;
use AlfaSix\AlfakitFileManager\Support\PathGuard;
use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ZipArchive;

/**
 * A file manager page for one or more Laravel filesystem disks.
 *
 * ```php
 * $panel->plugin(
 *     FileManagerPlugin::make()
 *         ->disks(['public', 's3' => 'Cloud'])
 *         ->root(fn (Team $tenant): string => 'teams/'.$tenant->getKey())
 *         ->maxUploadSize(20 * 1024)
 *         ->acceptedFileTypes(['image/*', 'application/pdf'])
 *         ->authorize(fn (User $user): bool => $user->can('manage-files'))
 *         ->can('delete', fn (User $user): bool => $user->is_admin),
 * );
 * ```
 *
 * Every operation is checked on the server: paths are normalised and must stay under the
 * root, the operation's ability must pass, and uploads go through the blocked and accepted
 * file type checks. On panels with tenancy each tenant gets its own root (`tenants/{key}`) by
 * default.
 */
class FileManagerPlugin implements Plugin
{
    use EvaluatesClosures;

    public const ID = 'file-manager';

    /** Abilities `can()` and `readOnly()` apply to. */
    public const ABILITIES = ['upload', 'createFolder', 'rename', 'move', 'copy', 'delete', 'restore', 'download', 'share'];

    /** Abilities that change files (refused while the manager is read-only). */
    public const WRITE_ABILITIES = ['upload', 'createFolder', 'rename', 'move', 'copy', 'delete', 'restore'];

    /** @var class-string<FileManager> */
    protected string $page = FileManager::class;

    /** @var array<int|string, string>|Closure */
    protected array|Closure $disks = ['public'];

    protected string|Closure|null $root = null;

    protected int|Closure|null $maxUploadSize = null;

    /** @var list<string>|Closure|null */
    protected array|Closure|null $acceptedFileTypes = null;

    /** @var list<string> */
    protected array $blockedExtensions = FileTypes::DEFAULT_BLOCKED_EXTENSIONS;

    /** @var list<string> */
    protected array $blockedMimeTypes = FileTypes::DEFAULT_BLOCKED_MIME_TYPES;

    protected bool|Closure|null $authorizeUsing = null;

    /** @var array<string, bool|Closure> */
    protected array $abilities = [];

    protected bool|Closure $isReadOnly = false;

    protected bool $hasTrash = true;

    protected ?int $trashRetentionDays = 30;

    protected bool $hasRecursiveSearch = true;

    protected int $maxSearchResults = 200;

    protected int $maxScannedEntries = 5000;

    protected int $maxListedEntries = 2000;

    protected int $maxItemsPerOperation = 500;

    protected bool $hasZipDownloads = true;

    protected int $maxZipSize = 512000;

    protected int $maxZipFiles = 1000;

    protected int $downloadRateLimit = 120;

    protected int $zipRateLimit = 10;

    protected int $signedUrlLifetime = 30;

    protected bool $hasShareLinks = true;

    /** @var list<int> */
    protected array $shareLinkExpiries = [60, 1440, 10080, 43200];

    protected bool $hasPublicLinks = false;

    protected int $textPreviewMaxSize = 512;

    protected string $defaultView = 'grid';

    protected string|Closure|null $navigationLabel = null;

    protected string|Closure|null $navigationGroup = null;

    protected string|Closure|null $navigationIcon = 'folder';

    protected int|Closure|null $navigationSort = null;

    protected bool|Closure $shouldRegisterNavigation = true;

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin registered on the current panel, or one with the default options when the
     * panel doesn't have it.
     */
    public static function get(): static
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        if ($panel->hasPlugin(self::ID)) {
            $plugin = $panel->getPlugin(self::ID);

            if ($plugin instanceof static) {
                return $plugin;
            }
        }

        return static::make();
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->pages([$this->getPage()]);
        $panel->frontendTranslations(['file-manager' => 'alfakit-file-manager::file-manager.ui']);
    }

    public function boot(Panel $panel): void {}

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'panel' => [alfakit()->getCurrentOrDefaultPanel()],
            'user' => [alfakit()->getCurrentOrDefaultPanel()->auth()->user()],
            'tenant' => [alfakit()->getTenant()],
            default => [],
        };
    }

    // ---------------------------------------------------------------- Page

    /**
     * Replace the page with your own subclass of `FileManager`.
     *
     * @param  class-string<FileManager>  $page
     */
    public function usingPage(string $page): static
    {
        if (! is_a($page, FileManager::class, true)) {
            throw new InvalidArgumentException("The file manager page [{$page}] must extend [".FileManager::class.'].');
        }

        $this->page = $page;

        return $this;
    }

    /**
     * @return class-string<FileManager>
     */
    public function getPage(): string
    {
        return $this->page;
    }

    // ---------------------------------------------------------------- Disks and root

    /**
     * The disks the manager offers: names (`['public']`) or names with labels
     * (`['public', 's3' => 'Cloud']`). The first one opens by default.
     *
     * @param  array<int|string, string>|Closure  $disks
     */
    public function disks(array|Closure $disks): static
    {
        $this->disks = $disks;

        return $this;
    }

    /**
     * The disks by name, with their labels.
     *
     * @return array<string, string>
     */
    public function getDisks(): array
    {
        $disks = $this->evaluate($this->disks);
        $resolved = [];

        foreach (is_array($disks) ? $disks : [] as $key => $value) {
            $name = is_string($key) ? $key : $value;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $resolved[$name] = is_string($key) && is_string($value) && $value !== '' ? $value : Str::headline($name);
        }

        if ($resolved === []) {
            throw new InvalidArgumentException('The file manager needs at least one disk.');
        }

        return $resolved;
    }

    public function getDefaultDisk(): string
    {
        return (string) array_key_first($this->getDisks());
    }

    public function hasDisk(mixed $disk): bool
    {
        return is_string($disk) && array_key_exists($disk, $this->getDisks());
    }

    /**
     * The folder of the disk the manager is limited to (`'uploads'`, or a closure receiving
     * `$tenant`, `$user`, `$disk`). On panels with tenancy it defaults to `tenants/{key}`;
     * pass `''` to share the whole disk between tenants.
     */
    public function root(string|Closure|null $root): static
    {
        $this->root = $root;

        return $this;
    }

    /**
     * The root for `$disk`, normalised. Throws when a closure returns an unsafe path, so a
     * misconfiguration can never open more of the disk than intended.
     */
    public function getRoot(string $disk): string
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $tenant = $panel->hasTenancy() ? alfakit()->getTenant() : null;

        if ($this->root === null) {
            if (! $panel->hasTenancy()) {
                return '';
            }

            abort_if($tenant === null, 404);

            $key = $tenant->getKey();

            return 'tenants/'.(is_scalar($key) ? (string) $key : '');
        }

        $root = $this->evaluate($this->root, ['disk' => $disk, 'tenant' => $tenant]);
        $normalized = PathGuard::normalize(is_string($root) ? $root : null);

        if ($normalized === null || ($panel->hasTenancy() && $tenant === null)) {
            throw new InvalidArgumentException('The file manager root must be a relative path without "..", hidden or empty segments.');
        }

        return $normalized;
    }

    // ---------------------------------------------------------------- Uploads

    /**
     * The largest file users may upload, in kilobytes (`alfakit.uploads.max_size_kb` by
     * default). Larger limits work: big files are uploaded in resumable chunks.
     */
    public function maxUploadSize(int|Closure|null $kilobytes): static
    {
        $this->maxUploadSize = $kilobytes;

        return $this;
    }

    /**
     * In kilobytes: `maxUploadSize()`, or the temporary upload limit. The upload endpoint
     * applies it through the page's upload policy.
     */
    public function getMaxUploadSize(): int
    {
        $temporary = (int) config('alfakit.uploads.max_size_kb', 12288);
        $own = $this->evaluate($this->maxUploadSize);

        return is_int($own) && $own > 0 ? $own : $temporary;
    }

    /**
     * Only accept these types: MIME types (`application/pdf`), wildcards (`image/*`) or
     * extensions (`.csv`). Checked against the type detected from the contents.
     *
     * @param  list<string>|Closure|null  $types
     */
    public function acceptedFileTypes(array|Closure|null $types): static
    {
        $this->acceptedFileTypes = $types;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getAcceptedFileTypes(): ?array
    {
        $types = $this->evaluate($this->acceptedFileTypes);

        return is_array($types) && $types !== [] ? array_values(array_filter($types, is_string(...))) : null;
    }

    /**
     * Replace the blocked extensions (by default scripts, HTML, SVG, executables and server
     * configuration files; see `FileTypes::DEFAULT_BLOCKED_EXTENSIONS`).
     *
     * @param  list<string>  $extensions
     */
    public function blockedFileTypes(array $extensions): static
    {
        $this->blockedExtensions = array_values(array_unique(array_map(static fn (string $extension): string => strtolower(ltrim($extension, '.')), $extensions)));

        return $this;
    }

    /**
     * Allow some of the extensions blocked by default (`allowFileTypes(['svg'])`). The
     * matching detected types (`image/svg+xml`) are allowed too.
     *
     * @param  list<string>  $extensions
     */
    public function allowFileTypes(array $extensions): static
    {
        $allowed = array_map(static fn (string $extension): string => strtolower(ltrim($extension, '.')), $extensions);
        $this->blockedExtensions = array_values(array_diff($this->blockedExtensions, $allowed));

        $mimes = [];

        foreach ($allowed as $extension) {
            array_push($mimes, ...\Symfony\Component\Mime\MimeTypes::getDefault()->getMimeTypes($extension));
        }

        $this->blockedMimeTypes = array_values(array_diff($this->blockedMimeTypes, $mimes));

        return $this;
    }

    /**
     * Replace the detected types that are never stored.
     *
     * @param  list<string>  $types
     */
    public function blockedMimeTypes(array $types): static
    {
        $this->blockedMimeTypes = array_values(array_map('strtolower', $types));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getBlockedExtensions(): array
    {
        return $this->blockedExtensions;
    }

    /**
     * @return list<string>
     */
    public function getBlockedMimeTypes(): array
    {
        return $this->blockedMimeTypes;
    }

    // ---------------------------------------------------------------- Authorisation

    /**
     * Who may open the file manager (and the picker). Without it every user of the panel may.
     */
    public function authorize(bool|Closure|null $callback = true): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function isAuthorized(): bool
    {
        return $this->authorizeUsing === null || $this->evaluateUserAuthorization($this->authorizeUsing);
    }

    /**
     * Who may run an operation: `upload`, `createFolder`, `rename`, `move`, `copy`, `delete`,
     * `restore`, `download` or `share`. The closure receives `$ability`, `$disk`, `$path`
     * (the folder or item, relative to the root), `$paths`, `$user` and `$tenant`.
     *
     * @param  string|list<string>  $abilities
     */
    public function can(string|array $abilities, bool|Closure $callback): static
    {
        foreach ((array) $abilities as $ability) {
            if (! in_array($ability, self::ABILITIES, true)) {
                throw new InvalidArgumentException("Unknown file manager ability [{$ability}]. Use one of: ".implode(', ', self::ABILITIES).'.');
            }

            $this->abilities[$ability] = $callback;
        }

        return $this;
    }

    /**
     * Browse, preview, download and share only.
     */
    public function readOnly(bool|Closure $condition = true): static
    {
        $this->isReadOnly = $condition;

        return $this;
    }

    public function isReadOnly(): bool
    {
        return (bool) $this->evaluate($this->isReadOnly);
    }

    /**
     * @param  list<string>  $paths
     */
    public function allows(string $ability, string $disk, string $path = '', array $paths = []): bool
    {
        if (! $this->isAuthorized()) {
            return false;
        }

        if (in_array($ability, self::WRITE_ABILITIES, true) && $this->isReadOnly()) {
            return false;
        }

        if ($ability === 'restore' && ! $this->hasTrash()) {
            return false;
        }

        if ($ability === 'share' && ! $this->hasShareLinks()) {
            return false;
        }

        $callback = $this->abilities[$ability] ?? true;

        return (bool) $this->evaluate($callback, [
            'ability' => $ability,
            'disk' => $disk,
            'path' => $path,
            'paths' => $paths === [] && $path !== '' ? [$path] : $paths,
        ]);
    }

    // ---------------------------------------------------------------- Trash

    /**
     * Deleted items go to a hidden `.trash` folder under the root, where they can be
     * restored, and are removed for good after `$retentionDays` (null: kept until the trash
     * is emptied). `trash(false)` deletes straight away.
     */
    public function trash(bool $condition = true, ?int $retentionDays = 30): static
    {
        $this->hasTrash = $condition;
        $this->trashRetentionDays = $retentionDays !== null && $retentionDays > 0 ? $retentionDays : null;

        return $this;
    }

    public function hasTrash(): bool
    {
        return $this->hasTrash;
    }

    public function getTrashRetentionDays(): ?int
    {
        return $this->trashRetentionDays;
    }

    // ---------------------------------------------------------------- Listing and search

    /**
     * Let users search the current folder and everything below it. The search stops after
     * `$maxResults` matches or `$maxScanned` entries, whichever comes first.
     */
    public function recursiveSearch(bool $condition = true, int $maxResults = 200, int $maxScanned = 5000): static
    {
        $this->hasRecursiveSearch = $condition;
        $this->maxSearchResults = max(1, $maxResults);
        $this->maxScannedEntries = max(1, $maxScanned);

        return $this;
    }

    public function hasRecursiveSearch(): bool
    {
        return $this->hasRecursiveSearch;
    }

    public function getMaxSearchResults(): int
    {
        return $this->maxSearchResults;
    }

    public function getMaxScannedEntries(): int
    {
        return $this->maxScannedEntries;
    }

    /**
     * The most entries a folder lists (the rest is reported as truncated).
     */
    public function maxListedEntries(int $count): static
    {
        $this->maxListedEntries = max(1, $count);

        return $this;
    }

    public function getMaxListedEntries(): int
    {
        return $this->maxListedEntries;
    }

    /**
     * The most items one move, copy, delete or restore may select, and the most files a
     * folder being moved or copied may contain.
     */
    public function maxItemsPerOperation(int $count): static
    {
        $this->maxItemsPerOperation = max(1, $count);

        return $this;
    }

    public function getMaxItemsPerOperation(): int
    {
        return $this->maxItemsPerOperation;
    }

    // ---------------------------------------------------------------- Downloads

    /**
     * Download selections as one zip archive of at most `$maxSizeKb` kilobytes of files and
     * `$maxFiles` files (needs the `zip` PHP extension).
     */
    public function zipDownloads(bool $condition = true, int $maxSizeKb = 512000, int $maxFiles = 1000): static
    {
        $this->hasZipDownloads = $condition;
        $this->maxZipSize = max(1, $maxSizeKb);
        $this->maxZipFiles = max(1, $maxFiles);

        return $this;
    }

    public function hasZipDownloads(): bool
    {
        return $this->hasZipDownloads && class_exists(ZipArchive::class);
    }

    public function getMaxZipSize(): int
    {
        return $this->maxZipSize;
    }

    public function getMaxZipFiles(): int
    {
        return $this->maxZipFiles;
    }

    /**
     * Downloads (and previews) per user and minute, and zip archives per user and minute.
     */
    public function rateLimits(int $downloadsPerMinute = 120, int $zipsPerMinute = 10): static
    {
        $this->downloadRateLimit = max(1, $downloadsPerMinute);
        $this->zipRateLimit = max(1, $zipsPerMinute);

        return $this;
    }

    public function getDownloadRateLimit(): int
    {
        return $this->downloadRateLimit;
    }

    public function getZipRateLimit(): int
    {
        return $this->zipRateLimit;
    }

    /**
     * Minutes the signed download, preview and thumbnail links of the panel stay valid.
     */
    public function signedUrlLifetime(int $minutes): static
    {
        $this->signedUrlLifetime = max(1, $minutes);

        return $this;
    }

    public function getSignedUrlLifetime(): int
    {
        return $this->signedUrlLifetime;
    }

    /**
     * The largest text file the preview shows, in kilobytes (larger files show their start).
     */
    public function textPreviewMaxSize(int $kilobytes): static
    {
        $this->textPreviewMaxSize = max(1, $kilobytes);

        return $this;
    }

    public function getTextPreviewMaxSize(): int
    {
        return $this->textPreviewMaxSize;
    }

    // ---------------------------------------------------------------- Sharing

    /**
     * Shareable links that anyone can open until they expire: signed temporary URLs, with
     * the expiry choices offered in minutes.
     *
     * @param  list<int>  $expiries
     */
    public function shareLinks(bool $condition = true, array $expiries = [60, 1440, 10080, 43200]): static
    {
        $this->hasShareLinks = $condition;
        $expiries = array_values(array_unique(array_filter($expiries, static fn (mixed $minutes): bool => is_int($minutes) && $minutes > 0)));
        sort($expiries);
        $this->shareLinkExpiries = $expiries === [] ? [1440] : $expiries;

        return $this;
    }

    public function hasShareLinks(): bool
    {
        return $this->hasShareLinks;
    }

    /**
     * @return list<int>
     */
    public function getShareLinkExpiries(): array
    {
        return $this->shareLinkExpiries;
    }

    /**
     * Public links that are stored, listed per file and can be revoked (optionally without
     * expiry). Needs the plugin's migration (`alfakit_file_manager_links`).
     */
    public function publicLinks(bool $condition = true): static
    {
        $this->hasPublicLinks = $condition;

        return $this;
    }

    public function hasPublicLinks(): bool
    {
        return $this->hasShareLinks && $this->hasPublicLinks;
    }

    // ---------------------------------------------------------------- Display

    /**
     * `grid` or `list`: how folders open until the user picks a view.
     */
    public function defaultView(string $view): static
    {
        if (! in_array($view, ['grid', 'list'], true)) {
            throw new InvalidArgumentException('The default view must be "grid" or "list".');
        }

        $this->defaultView = $view;

        return $this;
    }

    public function getDefaultView(): string
    {
        return $this->defaultView;
    }

    // ---------------------------------------------------------------- Navigation

    public function navigationLabel(string|Closure|null $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    public function getNavigationLabel(): string
    {
        $label = $this->evaluate($this->navigationLabel);

        return is_string($label) ? $label : (string) __('alfakit-file-manager::file-manager.page.navigation_label');
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) ? $group : null;
    }

    public function navigationIcon(string|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationIcon(): ?string
    {
        $icon = $this->evaluate($this->navigationIcon);

        return is_string($icon) ? $icon : null;
    }

    public function navigationSort(int|Closure|null $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        $sort = $this->evaluate($this->navigationSort);

        return is_int($sort) ? $sort : null;
    }

    public function registerNavigation(bool|Closure $condition = true): static
    {
        $this->shouldRegisterNavigation = $condition;

        return $this;
    }

    public function shouldRegisterNavigation(): bool
    {
        return (bool) $this->evaluate($this->shouldRegisterNavigation);
    }
}
