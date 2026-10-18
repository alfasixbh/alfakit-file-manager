<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFileManager;

use AlfaSix\AlfakitFileManager\Http\Controllers\ShareController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Loads the plugin's translations (`alfakit-file-manager::`) and the public
 * routes of shared links. The panel page and its signed file routes are registered by
 * `FileManagerPlugin`.
 */
class AlfakitFileManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/alfakit-file-manager.php', 'alfakit-file-manager');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).'/resources/lang', 'alfakit-file-manager');

        if (! $this->app->routesAreCached()) {
            $prefix = trim((string) config('alfakit-file-manager.route_prefix', 'alfakit-files'), '/');
            $middleware = (array) config('alfakit-file-manager.middleware', ['throttle:60,1']);

            Route::prefix($prefix)->middleware($middleware)->group(static function (): void {
                Route::get('/share', [ShareController::class, 'share'])->middleware('signed')->name('alfakit-file-manager.share');
                Route::get('/link/{token}', [ShareController::class, 'link'])->where('token', '[A-Za-z0-9]{48}')->name('alfakit-file-manager.link');
            });
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__).'/config/alfakit-file-manager.php' => config_path('alfakit-file-manager.php'),
            ], 'alfakit-file-manager-config');

            $this->publishesMigrations([
                dirname(__DIR__).'/database/migrations' => database_path('migrations'),
            ], 'alfakit-file-manager-migrations');

            $this->publishes([
                dirname(__DIR__).'/resources/lang' => $this->app->langPath('vendor/alfakit-file-manager'),
            ], 'alfakit-file-manager-translations');
        }
    }
}
