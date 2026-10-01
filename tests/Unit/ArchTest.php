<?php

declare(strict_types=1);

arch()->preset()->php();
arch()->preset()->strict();
arch()->preset()->laravel();
arch()->preset()->security()->ignoring([
    'assert',
]);

arch('controllers')
    ->expect('App\Http\Controllers')
    ->not->toBeUsed();

//

arch('only the YouTube Music adapter knows ytmusicapi')
    ->expect('Ytmusicapi')
    ->toOnlyBeUsedIn('App\Services\Music\YouTubeMusic');

arch('the YouTube Music adapter stays behind the provider contracts')
    ->expect('App\Services\Music\YouTubeMusic')
    ->toOnlyBeUsedIn([
        'App\Services\Music\YouTubeMusic',
        'App\Providers',
    ]);

arch('actions depend on contracts, never on a concrete adapter')
    ->expect('App\Actions')
    ->not->toUse('App\Services\Music\YouTubeMusic');
