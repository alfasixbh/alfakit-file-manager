<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $table = 'fm_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['gallery' => 'array'];
    }
}
