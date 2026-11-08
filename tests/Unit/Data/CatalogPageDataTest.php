<?php

declare(strict_types=1);

use App\Data\CatalogTrackData;
use App\Enums\ArtistRole;
use App\Enums\Provider;
use App\Enums\SourceKind;
use App\Services\Music\Data\ProviderRef;
use App\Services\Music\Data\RemoteArtist;
use App\Services\Music\Data\RemoteTrack;
use App\Services\Thumbnails\ThumbnailProxy;

function catalogDataTrack(?int $seconds, ?string $thumbnail = null): RemoteTrack
{
    return new RemoteTrack(
        ref: new ProviderRef(Provider::YouTubeMusic, 'vid00000001'),
        title: 'Du hast',
        artists: [new RemoteArtist(new ProviderRef(Provider::YouTubeMusic, 'UCartist'), 'Rammstein', ArtistRole::Main)],
        album: null,
        durationSeconds: $seconds,
        isrc: null,
        kind: SourceKind::Audio,
        isExplicit: false,
        isAvailable: true,
        thumbnailUrl: $thumbnail,
    );
}

it('formats a catalog track like a library track', function (): void {
    $url = 'https://lh3.googleusercontent.com/cover.jpg';

    $data = CatalogTrackData::fromRemote(catalogDataTrack(215, $url), ['Metal'], true, 12);

    expect($data->duration)->toBe('3:35')
        ->and($data->durationSeconds)->toBe(215)
        ->and($data->thumbnailUrl)->toBe(ThumbnailProxy::url($url))
        ->and($data->isAvailable)->toBeTrue()
        ->and($data->artistId)->toBe('UCartist')
        ->and($data->genres)->toBe(['Metal'])
        ->and($data->inLibrary)->toBeTrue()
        ->and($data->plays)->toBe(12);
});

it('formats hours in a catalog track duration', function (): void {
    expect(CatalogTrackData::fromRemote(catalogDataTrack(3723), [], false)->duration)->toBe('1:02:03');
});

it('keeps an unknown duration null', function (): void {
    $data = CatalogTrackData::fromRemote(catalogDataTrack(null), [], false);

    expect($data->duration)->toBeNull()
        ->and($data->durationSeconds)->toBeNull();
});
