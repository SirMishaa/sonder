<?php

declare(strict_types=1);

use App\Actions\CountAlbumTracks;
use App\Actions\DescribeAlbum;
use App\Actions\DescribeArtist;
use App\Actions\DescribePublicPlaylist;
use App\Actions\SearchCatalog;
use App\Exceptions\Providers\ProviderUnavailable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeProviderAdapter;

it('caches a search for ten minutes per normalized query', function (): void {
    $this->fakeProvider()->searchResults = FakeProviderAdapter::aCatalogResults();

    resolve(SearchCatalog::class)->handle('  RAMMSTEIN ');
    resolve(SearchCatalog::class)->handle('rammstein');

    expect($this->fakeProvider()->callCount('search'))->toBe(1);

    $this->travel(11)->minutes();
    resolve(SearchCatalog::class)->handle('rammstein');

    expect($this->fakeProvider()->callCount('search'))->toBe(2);
});

it('returns empty results for a blank query without calling the provider', function (): void {
    $results = resolve(SearchCatalog::class)->handle("  \t ");

    expect($results->albums)->toBe([])
        ->and($this->fakeProvider()->calls)->toBe([]);
});

it('caches an album for a day and gets it back equal', function (): void {
    $album = FakeProviderAdapter::aCatalogAlbum();
    $this->fakeProvider()->albums[$album->summary->ref->externalId] = $album;
    $id = $album->summary->ref->externalId;

    expect(resolve(DescribeAlbum::class)->handle($id))->toEqual($album)
        ->and(resolve(DescribeAlbum::class)->handle($id))->toEqual($album)
        ->and($this->fakeProvider()->callCount('album'))->toBe(1);

    $this->travel(25)->hours();
    resolve(DescribeAlbum::class)->handle($id);

    expect($this->fakeProvider()->callCount('album'))->toBe(2);
});

it('caches an artist for a day', function (): void {
    $artist = FakeProviderAdapter::aCatalogArtist();
    $this->fakeProvider()->artists['UC1'] = $artist;

    resolve(DescribeArtist::class)->handle('UC1');
    resolve(DescribeArtist::class)->handle('UC1');

    expect($this->fakeProvider()->callCount('artist'))->toBe(1);
});

it('does not cache a failed lookup', function (): void {
    $this->fakeProvider()->unavailable = true;

    expect(fn () => resolve(DescribeAlbum::class)->handle('MPREb_x'))->toThrow(ProviderUnavailable::class);

    $this->fakeProvider()->unavailable = false;
    $this->fakeProvider()->albums['MPREb_x'] = FakeProviderAdapter::aCatalogAlbum('MPREb_x');

    expect(resolve(DescribeAlbum::class)->handle('MPREb_x')->summary->title)->toBe('Zeit')
        ->and($this->fakeProvider()->callCount('album'))->toBe(2);
});

it('skips albums that fail to load when counting tracks', function (): void {
    $this->fakeProvider()->albums['MPREb_a'] = FakeProviderAdapter::aCatalogAlbum('MPREb_a', trackCount: 7);

    resolve(DescribeAlbum::class)->handle('MPREb_a');
    $this->fakeProvider()->unavailable = true;

    expect(resolve(CountAlbumTracks::class)->handle(['MPREb_a', 'MPREb_b']))->toBe(['MPREb_a' => 7]);
});

it('counts only cached albums when asked', function (): void {
    $this->fakeProvider()->albums['MPREb_a'] = FakeProviderAdapter::aCatalogAlbum('MPREb_a', trackCount: 7);
    resolve(DescribeAlbum::class)->handle('MPREb_a');
    $this->fakeProvider()->calls = [];

    expect(resolve(CountAlbumTracks::class)->handle(['MPREb_a', 'MPREb_b'], cachedOnly: true))->toBe(['MPREb_a' => 7])
        ->and($this->fakeProvider()->calls)->toBe([]);
});

it('caches a public playlist for a day', function (): void {
    $this->fakeProvider()->publicPlaylists['PL_1'] = FakeProviderAdapter::aPlaylist('PL_1');

    resolve(DescribePublicPlaylist::class)->handle('PL_1');
    resolve(DescribePublicPlaylist::class)->handle('PL_1');

    expect($this->fakeProvider()->callCount('publicPlaylist'))->toBe(1);

    $this->travel(25)->hours();
    resolve(DescribePublicPlaylist::class)->handle('PL_1');

    expect($this->fakeProvider()->callCount('publicPlaylist'))->toBe(2);
});

it('gets cached catalog data back equal after a real serialize round-trip', function (): void {
    config(['cache.stores.array.serialize' => true]);
    Cache::forgetDriver('array');

    $album = FakeProviderAdapter::aCatalogAlbum();
    $results = FakeProviderAdapter::aCatalogResults();
    $playlist = FakeProviderAdapter::aPlaylist('PL_1');
    $this->fakeProvider()->albums[$album->summary->ref->externalId] = $album;
    $this->fakeProvider()->searchResults = $results;
    $this->fakeProvider()->publicPlaylists['PL_1'] = $playlist;

    $cases = [
        [fn () => resolve(DescribeAlbum::class)->handle($album->summary->ref->externalId), $album, 'album'],
        [fn () => resolve(SearchCatalog::class)->handle('rammstein'), $results, 'search'],
        [fn () => resolve(DescribePublicPlaylist::class)->handle('PL_1'), $playlist, 'publicPlaylist'],
    ];

    foreach ($cases as [$fetch, $original, $method]) {
        $fetch();
        $cached = $fetch();

        expect($cached)->toEqual($original)
            ->and($cached)->not->toBe($original)
            ->and($this->fakeProvider()->callCount($method))->toBe(1);
    }
});
