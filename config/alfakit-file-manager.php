<?php

declare(strict_types=1);

/*
 * App-wide settings of alfasix/alfakit-file-manager. Everything else is configured per
 * panel on `FileManagerPlugin::make()`. Publish with
 * `php artisan vendor:publish --tag=alfakit-file-manager-config`.
 */
return [
    // The public routes shared links are served from: `/{prefix}/share` (signed temporary
    // links) and `/{prefix}/link/{token}` (stored public links).
    'route_prefix' => 'alfakit-files',

    // Middleware of those routes. They need no session: links work for anyone who has them.
    'middleware' => ['throttle:60,1'],
];
