<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager\Tests;

use AlfaSix\Alfakit\Tests\TestCase as AlfakitTestCase;
use AlfaSix\AlfakitFileManager\AlfakitFileManagerServiceProvider;
use AlfaSix\AlfakitFileManager\Tests\Fixtures\Panels\FilesPanelProvider;
use AlfaSix\AlfakitFileManager\Tests\Fixtures\Panels\FilesTenantPanelProvider;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

abstract class TestCase extends AlfakitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('archive');
        Storage::fake('local');
    }

    /**
     * The running test (Pest binds test closures to it).
     */
    public static function current(): static
    {
        $test = \Pest\TestSuite::getInstance()->test;

        if (! $test instanceof static) {
            throw new RuntimeException('No '.static::class.' is running.');
        }

        return $test;
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            MediaLibraryServiceProvider::class,
            AlfakitFileManagerServiceProvider::class,
            FilesPanelProvider::class,
            FilesTenantPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filesystems.disks.archive', ['driver' => 'local', 'root' => storage_path('app/archive')]);
        $app['config']->set('alfakit.uploads.temporary_disk', 'local');
        $app['config']->set('media-library.disk_name', 'archive');
        $app['config']->set('media-library.queue_conversions_by_default', true);
        $app['config']->set('media-library.queue_connection_name', 'null');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }
}
