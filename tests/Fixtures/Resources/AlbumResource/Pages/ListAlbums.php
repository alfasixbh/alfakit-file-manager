<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Tests\Fixtures\Resources\AlbumResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\AlfakitFileManager\Tests\Fixtures\Resources\AlbumResource;

class ListAlbums extends ListRecords
{
    protected static string $resource = AlbumResource::class;
}
