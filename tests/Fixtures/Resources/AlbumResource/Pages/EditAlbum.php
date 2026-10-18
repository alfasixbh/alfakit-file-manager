<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Tests\Fixtures\Resources\AlbumResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\AlfakitFileManager\Tests\Fixtures\Resources\AlbumResource;

class EditAlbum extends EditRecord
{
    protected static string $resource = AlbumResource::class;
}
