<?php

declare(strict_types=1);

use App\Models\Playlist;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeProviderAdapter;

it('renders an album with total plays and in-library count', function (): void {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for(YouTubeMusicAccount::factory()->for($user), 'youtubeMusicAccount')->create();
    $playlist->tracks()->create(['youtube_video_id' => 'xXp4GnC1Z3Q', 'title' => 'Rendezvous', 'artists' => 'Ludwig Göransson']);
    $this->fakeProvider()->albums['MPREb_1'] = FakeProviderAdapter::aCatalogAlbum('MPREb_1');

    $this->actingAs($user)->get(route('album.show', 'MPREb_1'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('album/Show')
            ->where('page.album.title', 'Zeit')
            ->where('page.album.year', 2022)
            ->where('page.album.trackCount', 11)
            ->where('page.totalPlays', 1000)
            ->where('page.inLibraryCount', 1)
            ->where('page.tracks.0.plays', 1000)
            ->where('page.tracks.0.inLibrary', true));
});

it('loads more albums by the artist after the page', function (): void {
    $artist = FakeProviderAdapter::aCatalogArtist('UC1');
    $this->fakeProvider()->albums['MPREb_1'] = new App\Services\Music\Data\CatalogAlbum(
        new App\Services\Music\Data\CatalogAlbumSummary(
            new App\Services\Music\Data\ProviderRef(App\Enums\Provider::YouTubeMusic, 'MPREb_1'), 'Current', 'Album', 2022,
            [new App\Services\Music\Data\RemoteArtist(new App\Services\Music\Data\ProviderRef(App\Enums\Provider::YouTubeMusic, 'UC1'), 'Rammstein', App\Enums\ArtistRole::Main)],
            null, false,
        ),
        null, 11, 2700, [], [],
    );
    $this->fakeProvider()->artists['UC1'] = $artist;

    $this->actingAs(User::factory()->create())->get(route('album.show', 'MPREb_1'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('moreByArtist')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('moreByArtist', 1)
                ->where('moreByArtist.0.title', 'Zeit')));
});

it('shows a catalog error instead of failing', function (): void {
    $this->fakeProvider()->unavailable = true;

    $this->actingAs(User::factory()->create())->get(route('album.show', 'MPREb_1'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page', null)
            ->where('catalogError', fn ($message): bool => is_string($message) && $message !== ''));
});

it('skips building the page on a partial reload of the deferred props', function (): void {
    $user = User::factory()->create();
    $artist = FakeProviderAdapter::aCatalogArtist('UC1');
    $this->fakeProvider()->albums['MPREb_1'] = new App\Services\Music\Data\CatalogAlbum(
        new App\Services\Music\Data\CatalogAlbumSummary(
            new App\Services\Music\Data\ProviderRef(App\Enums\Provider::YouTubeMusic, 'MPREb_1'), 'Current', 'Album', 2022,
            [new App\Services\Music\Data\RemoteArtist(new App\Services\Music\Data\ProviderRef(App\Enums\Provider::YouTubeMusic, 'UC1'), 'Rammstein', App\Enums\ArtistRole::Main)],
            null, false,
        ),
        null, 11, 2700, [FakeProviderAdapter::aTrack()], [],
    );
    $this->fakeProvider()->artists['UC1'] = $artist;

    $response = $this->actingAs($user)->get(route('album.show', 'MPREb_1'));
    $version = $response->headers->get('X-Inertia-Version') ?? inertia()->getVersion();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'album/Show',
        'X-Inertia-Partial-Data' => 'moreByArtist',
    ])->get(route('album.show', 'MPREb_1'))
        ->assertOk()
        ->assertJsonCount(1, 'props.moreByArtist')
        ->assertJsonMissingPath('props.page');

    $trackQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains((string) $query['query'], 'from "tracks"'));

    expect($trackQueries)->toBeEmpty();
    expect($this->fakeProvider()->callCount('album'))->toBe(1);
});
