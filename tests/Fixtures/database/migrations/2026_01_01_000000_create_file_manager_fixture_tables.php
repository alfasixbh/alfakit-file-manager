<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // spatie/laravel-medialibrary, for the `toMediaCollection()` bridge.
        (require dirname(__DIR__, 7).'/vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub')->up();

        Schema::create('fm_albums', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('fm_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('attachment')->nullable();
            $table->json('gallery')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fm_documents');
        Schema::dropIfExists('fm_albums');
        Schema::dropIfExists('media');
    }
};
