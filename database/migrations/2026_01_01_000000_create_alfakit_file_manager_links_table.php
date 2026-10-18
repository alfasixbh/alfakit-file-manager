<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Public links of alfasix/alfakit-file-manager (`FileManagerPlugin::publicLinks()`). Only a
 * hash of each token is searchable; the token itself is stored encrypted so the link can be
 * shown again, and is useless without the application key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alfakit_file_manager_links', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->text('token');
            $table->string('panel');
            $table->string('disk');
            $table->string('root', 1024)->default('');
            $table->string('path', 1024);
            $table->string('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->index(['panel', 'disk']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_file_manager_links');
    }
};
