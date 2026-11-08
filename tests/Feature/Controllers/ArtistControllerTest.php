<?php

declare(strict_types=1);

use App\Models\Listen;
use App\Models\Playlist;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeProviderAdapter;

it('renders an artist with library stats and genres', function (): void {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for(YouTubeMusicAccount::factory()->for($user), 'youtubeMusicAccount')->create();
    $playlist->tracks()->create(['youtube_video_id' => 'video000001', 'title' => 'Zeit', 'artists' => 'Rammstein', 'album' => 'Zeit']);
    Listen::factory()->for($user)->create(['title' => 'Zeit', 'artists' => 'Rammstein', 'listened_seconds' => 180]);
    $this->fakeProvider()->artists['UC1'] = FakeProviderAdapter::aCatalogArtist('UC1');

    $this->actingAs($user)->get(route('artist.show', 'UC1'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('artist/Show')
            ->where('page.artist.name', 'Rammstein')
            ->where('page.monthlyListeners', '9.1M')
            ->where('page.inLibrary.trackCount', 1)
            ->where('page.inLibrary.listenCount', 1)
            ->where('page.inLibrary.listenedMinutes', 3)
            ->has('page.albums', 1)
            ->has('page.tracks', 1)
            ->where('page.tracks.0.inLibrary', false));
});

it('takes a missing top track duration from a cached album', function (): void {
    $artist = FakeProviderAdapter::aCatalogArtist('UC1');
    $bare = new App\Services\Music\Data\RemoteTrack(
        $artist->topTracks[0]->ref, 'Rendezvous', $artist->topTracks[0]->artists, $artist->topTracks[0]->album,
        null, null, $artist->topTracks[0]->kind, false, true, null,
    );
    $this->fakeProvider()->artists['UC1'] = new App\Services\Music\Data\CatalogArtist(
        $artist->summary, null, null, null, [$bare], $artist->albums, [], [], [],
    );

    $this->actingAs(User::factory()->create())->get(route('artist.show', 'UC1'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page.tracks.0.durationSeconds', null));

    Cache::put(App\Actions\DescribeAlbum::cacheKey('MPREb_LJSVi8szMQL'), FakeProviderAdapter::aCatalogAlbum());
    Cache::forget(App\Actions\DescribeArtist::cacheKey('UC1'));

    $this->actingAs(User::factory()->create())->get(route('artist.show', 'UC1'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page.tracks.0.durationSeconds', 199));
});

it('loads album track counts after the page', function (): void {
    $this->fakeProvider()->artists['UC1'] = FakeProviderAdapter::aCatalogArtist('UC1');
    $this->fakeProvider()->albums['MPREb_LJSVi8szMQL'] = FakeProviderAdapter::aCatalogAlbum(trackCount: 11);

    $this->actingAs(User::factory()->create())->get(route('artist.show', 'UC1'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('albumTrackCounts')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload->where('albumTrackCounts.MPREb_LJSVi8szMQL', 11)));
});

it('shows a catalog error instead of failing', function (): void {
    $this->fakeProvider()->unavailable = true;

    $this->actingAs(User::factory()->create())->get(route('artist.show', 'UC1'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page', null)
            ->where('catalogError', fn ($message): bool => is_string($message) && $message !== ''));
});
