# AlfaKit File Manager

<p><picture><source media="(prefers-color-scheme: dark)" srcset="../../../art/alfasix/alfa-six-micro-white.svg"><img src="../../../art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

A Google-Drive-lite file manager for any Laravel filesystem disk in [AlfaKit](https://alfakit.dev) panels: folders, grid and list views, search, multi-selection, drag-and-drop uploads with progress, move, copy, rename, trash, previews, zip downloads and shareable links, plus a `FileManagerPicker` form field. Every operation is authorised, path-checked and tenant-scoped on the server.

## Installation

```bash
composer require alfasix/alfakit-file-manager
```

The UI ships with `@alfakit/react`, `@alfakit/vue` and `@alfakit/svelte`. For revocable public links, publish and run the migration:

```bash
php artisan vendor:publish --tag=alfakit-file-manager-migrations
php artisan migrate
```

## Register

```php
use AlfaSix\AlfakitFileManager\FileManagerPlugin;

$panel->plugin(
    FileManagerPlugin::make()
        ->disks(['public', 's3' => 'Cloud'])
        ->maxUploadSize(20 * 1024)
        ->acceptedFileTypes(['image/*', 'application/pdf'])
        ->authorize(fn (User $user): bool => $user->can('manage-files')),
);
```

## Picker field

```php
use AlfaSix\AlfakitFileManager\Forms\Components\FileManagerPicker;

FileManagerPicker::make('attachment')->directory('invoices')->acceptedFileTypes(['application/pdf']);
```

Full documentation: [alfakit.dev/docs/plugins/file-manager](https://alfakit.dev/docs/plugins/file-manager).

## License

MIT. See [LICENSE.md](LICENSE.md).
