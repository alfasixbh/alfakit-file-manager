<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Tests\Fixtures\Resources;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitFileManager\Forms\Components\FileManagerPicker;
use AlfaSix\AlfakitFileManager\Tests\Fixtures\Models\Document;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $slug = 'documents';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            FileManagerPicker::make('attachment')->directory('invoices')->acceptedFileTypes(['application/pdf']),
            FileManagerPicker::make('gallery')->multiple()->maxFiles(3)->acceptedFileTypes(['image/*']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => DocumentResource\Pages\ListDocuments::route('/'),
            'create' => DocumentResource\Pages\CreateDocument::route('/create'),
            'edit' => DocumentResource\Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
