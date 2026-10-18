<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Tests\Fixtures\Resources\DocumentResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\AlfakitFileManager\Tests\Fixtures\Resources\DocumentResource;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;
}
