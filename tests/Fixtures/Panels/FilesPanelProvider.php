<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitFileManager\FileManagerPlugin;
use AlfaSix\AlfakitFileManager\Tests\Fixtures\Resources\AlbumResource;
use AlfaSix\AlfakitFileManager\Tests\Fixtures\Resources\DocumentResource;

class FilesPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('files')
            ->path('files-panel')
            ->login()
            ->middleware(['web'])
            ->resources([DocumentResource::class, AlbumResource::class])
            ->plugin(FileManagerPlugin::make()->disks(['public', 'archive' => 'Archive'])->publicLinks());
    }
}
