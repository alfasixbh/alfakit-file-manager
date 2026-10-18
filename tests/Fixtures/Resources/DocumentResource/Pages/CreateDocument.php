<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Tests\Fixtures\Resources\DocumentResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\AlfakitFileManager\Tests\Fixtures\Resources\DocumentResource;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;
}
