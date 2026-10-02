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

arch('actions reach metadata services through their gateway contracts')
    ->expect('App\Actions')
    ->not->toUse([
        'App\Services\Metadata\CreditsFm\HttpCreditsFmGateway',
        'App\Services\Metadata\CreditsFm\RateLimitedCreditsFmGateway',
        'App\Services\Metadata\MusicBrainz\HttpMusicBrainzGateway',
        'App\Services\Metadata\MusicBrainz\RateLimitedMusicBrainzGateway',
        'App\Services\Metadata\LastFm\HttpLastFmGateway',
        'App\Services\Metadata\LastFm\RateLimitedLastFmGateway',
    ]);

arch('only the metadata gateways call metadata services over HTTP')
    ->expect('App\Services\Metadata')
    ->not->toUse('Illuminate\Support\Facades\Http')
    ->ignoring([
        'App\Services\Metadata\CreditsFm\HttpCreditsFmGateway',
        'App\Services\Metadata\MusicBrainz\HttpMusicBrainzGateway',
        'App\Services\Metadata\LastFm\HttpLastFmGateway',
    ]);
