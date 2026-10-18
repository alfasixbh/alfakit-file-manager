<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\AlfakitFileManager\FileManagerPlugin;
use AlfaSix\AlfakitFileManager\Pages\FileManager;
use AlfaSix\AlfakitFileManager\Support\Drive;
use AlfaSix\AlfakitFileManager\Support\FileTypes;
use AlfaSix\AlfakitFileManager\Support\PathGuard;
use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Pick files from the panel's file manager into a form. The state is the file's path on the
 * disk (`tenants/4/invoices/march.pdf`), so `Storage::disk('public')->url($path)` works; with
 * `multiple()` a list of paths.
 *
 * ```php
 * FileManagerPicker::make('attachment')
 *     ->disk('public')
 *     ->directory('invoices')
 *     ->acceptedFileTypes(['application/pdf', 'image/*']);
 * ```
 *
 * The picker browses through the file manager page, so it shows exactly what the user may see
 * there (the tenant's root, the plugin's authorisation). On save every path is checked again:
 * it must be a file under the root, outside the trash, of an accepted type.
 *
 * With alfasix/alfakit-media-library (spatie/laravel-medialibrary) installed,
 * `toMediaCollection('gallery')` adds copies of the picked files to the record's media
 * collection instead of saving paths.
 */
class FileManagerPicker extends Field
{
    protected string|Closure|null $disk = null;

    protected string|Closure|null $directory = null;

    protected bool|Closure $isMultiple = false;

    protected int|Closure|null $maxFiles = null;

    /** @var list<string>|Closure|null */
    protected array|Closure|null $acceptedFileTypes = null;

    protected ?string $mediaCollection = null;

    public function disk(string|Closure|null $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * The plugin's disk the picker browses (the first one by default).
     */
    public function getDiskName(): string
    {
        $plugin = $this->getPlugin();
        $disk = $this->evaluate($this->disk) ?? $plugin->getDefaultDisk();

        if (! $plugin->hasDisk($disk)) {
            throw new LogicException("The disk [{$disk}] is not one of the file manager's disks.");
        }

        return (string) $disk;
    }

    /**
     * The folder (under the file manager's root) the picker opens in.
     */
    public function directory(string|Closure|null $directory): static
    {
        $this->directory = $directory;

        return $this;
    }

    public function getDirectory(): string
    {
        return PathGuard::normalize($this->evaluate($this->directory)) ?? '';
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function maxFiles(int|Closure|null $count): static
    {
        $this->maxFiles = $count;

        return $this;
    }

    public function getMaxFiles(): ?int
    {
        $count = $this->evaluate($this->maxFiles);

        return is_int($count) && $count > 0 ? $count : null;
    }

    /**
     * Only these types can be picked: MIME types, wildcards (`image/*`) or extensions (`.csv`).
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
     * Add copies of the picked files to the record's media collection when the form is saved
     * (needs spatie/laravel-medialibrary and a `HasMedia` model).
     */
    public function toMediaCollection(string $collection = 'default'): static
    {
        if (! interface_exists(\Spatie\MediaLibrary\HasMedia::class)) {
            throw new LogicException('toMediaCollection() needs spatie/laravel-medialibrary (composer require alfasix/alfakit-media-library).');
        }

        $this->mediaCollection = $collection;

        return $this;
    }

    public function getMediaCollection(): ?string
    {
        return $this->mediaCollection;
    }

    public function getPlugin(): FileManagerPlugin
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        if (! $panel->hasPlugin(FileManagerPlugin::ID)) {
            throw new LogicException('FileManagerPicker needs FileManagerPlugin registered on the panel ['.$panel->getId().'].');
        }

        $plugin = $panel->getPlugin(FileManagerPlugin::ID);

        if (! $plugin instanceof FileManagerPlugin) {
            throw new LogicException('The file-manager plugin of the panel is not a FileManagerPlugin.');
        }

        return $plugin;
    }

    public function getDrive(): Drive
    {
        return new Drive($this->getPlugin(), $this->getDiskName());
    }

    protected function getPage(): FileManager
    {
        return app($this->getPlugin()->getPage());
    }

    // ---------------------------------------------------------------- State

    protected function getDefaultStateWhenEmpty(): mixed
    {
        return $this->isMultiple() ? [] : null;
    }

    public function normalizeClientState(): void
    {
        $state = $this->getState();

        if ($this->isMultiple()) {
            $paths = is_array($state) ? $state : (is_string($state) && $state !== '' ? [$state] : []);
            $this->state(array_values(array_unique(array_filter($paths, static fn (mixed $path): bool => is_string($path) && $path !== ''))));

            return;
        }

        if (is_array($state)) {
            $first = reset($state);
            $this->state(is_string($first) && $first !== '' ? $first : null);
        } elseif ($state === '') {
            $this->state(null);
        }
    }

    /**
     * @return list<string>
     */
    protected function statePaths(): array
    {
        $state = $this->getState();
        $paths = is_array($state) ? $state : [$state];

        return array_values(array_filter($paths, static fn (mixed $path): bool => is_string($path) && $path !== ''));
    }

    /**
     * Whether a submitted disk path is a file the picker may hold.
     */
    public function isPickable(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        $drive = $this->getDrive();
        $relative = $drive->relative($value);

        if ($relative === null || $relative === '' || PathGuard::normalize($relative) !== $relative || ! $drive->isFile($relative)) {
            return false;
        }

        $accepted = $this->getAcceptedFileTypes();

        return $accepted === null || FileTypes::matches($accepted, $drive->mimeType($relative), $relative);
    }

    protected function getTypeValidationRules(): array
    {
        $rules = [];

        if ($this->isMultiple()) {
            $rules[] = 'array';

            if (($max = $this->getMaxFiles()) !== null) {
                $rules[] = 'max:'.$max;
            }
        } else {
            $rules[] = 'string';
        }

        $rules[] = function (string $attribute, mixed $value, Closure $fail): void {
            foreach (is_array($value) ? $value : [$value] as $path) {
                if (! $this->isPickable($path)) {
                    $fail(__('alfakit-file-manager::file-manager.errors.picker_invalid'));

                    return;
                }
            }
        };

        return $rules;
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        if ($this->isMultiple()) {
            return is_array($state) ? array_values($state) : [];
        }

        return is_string($state) && $state !== '' ? $state : null;
    }

    // ---------------------------------------------------------------- Media library

    public function isDehydrated(): bool
    {
        return $this->mediaCollection === null && parent::isDehydrated();
    }

    /**
     * `toMediaCollection()`: add copies of the picked files to the record's collection.
     */
    public function saveRelationships(): void
    {
        if ($this->mediaCollection === null || ! $this->shouldSaveRelationships()) {
            return;
        }

        $record = $this->getRecord();

        if (! $record instanceof \Spatie\MediaLibrary\HasMedia || ! $record->exists) {
            return;
        }

        $disk = $this->getDiskName();

        foreach ($this->statePaths() as $path) {
            if ($this->isPickable($path)) {
                $record->addMediaFromDisk($path, $disk)->preservingOriginal()->toMediaCollection($this->mediaCollection);
            }
        }

        $this->state($this->getDefaultStateWhenEmpty());
    }

    // ---------------------------------------------------------------- Schema

    /**
     * @return list<array<string, mixed>>
     */
    protected function getPickedFiles(): array
    {
        $drive = $this->getDrive();
        $page = $this->getPage();
        $files = [];

        foreach ($this->statePaths() as $path) {
            $relative = $drive->relative($path);
            $entry = $relative === null || PathGuard::normalize($relative) !== $relative ? null : $drive->entry($relative);

            if ($entry === null || $entry['type'] !== 'file') {
                $files[] = ['value' => $path, 'name' => PathGuard::basename($path), 'missing' => true];

                continue;
            }

            $files[] = [...$page->serializeEntry($drive, $entry, ['picker' => true, 'accept' => $this->getAcceptedFileTypes()]), 'value' => $path];
        }

        return $files;
    }

    protected function getSchemaProps(): array
    {
        $accept = $this->getAcceptedFileTypes();

        return array_filter([
            'multiple' => $this->isMultiple() ?: null,
            'maxFiles' => $this->getMaxFiles(),
            'accept' => $accept,
            'files' => $this->getPickedFiles(),
            'browser' => $this->getPage()->serializeManager('picker', [
                'disk' => $this->getDiskName(),
                'directory' => $this->getDirectory(),
                'multiple' => $this->isMultiple(),
                'accept' => $accept,
            ]),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
