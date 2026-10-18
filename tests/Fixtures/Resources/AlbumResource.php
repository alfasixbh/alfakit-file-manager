<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Tests\Fixtures\Resources;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitFileManager\Forms\Components\FileManagerPicker;
use AlfaSix\AlfakitFileManager\Tests\Fixtures\Models\Album;

class AlbumResource extends Resource
{
    protected static ?string $model = Album::class;

    protected static ?string $slug = 'albums';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            FileManagerPicker::make('photos')->multiple()->acceptedFileTypes(['image/*'])->toMediaCollection('gallery'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => AlbumResource\Pages\ListAlbums::route('/'),
            'edit' => AlbumResource\Pages\EditAlbum::route('/{record}/edit'),
        ];
    }
}
