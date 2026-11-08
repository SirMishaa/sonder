<?php

declare(strict_types=1);

use App\Enums\CatalogFilter;
use App\Models\Playlist;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeProviderAdapter;

it('renders search results for a query', function (): void {
    $this->fakeProvider()->searchResults = FakeProviderAdapter::aCatalogResults();
    $this->fakeProvider()->albums['MPREb_LJSVi8szMQL'] = FakeProviderAdapter::aCatalogAlbum();

    $this->actingAs(User::factory()->create())->get(route('search.index', ['q' => 'Rammstein']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('search/Index')
            ->where('query', 'Rammstein')
            ->where('type', null)
            ->missing('results')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('results.topArtist.name', 'Rammstein')
                ->has('results.tracks', 1)
                ->has('results.albums', 1)
                ->where('results.albums.0.trackCount', null)
                ->where('catalogError', null)));
});

it('loads album track counts after the results', function (): void {
    $this->fakeProvider()->searchResults = FakeProviderAdapter::aCatalogResults();
    $this->fakeProvider()->albums['MPREb_LJSVi8szMQL'] = FakeProviderAdapter::aCatalogAlbum(trackCount: 11);

    $this->actingAs(User::factory()->create())->get(route('search.index', ['q' => 'Rammstein']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('albumTrackCounts.MPREb_LJSVi8szMQL', 11)));
});

it('renders an empty search', function (): void {
    $this->actingAs(User::factory()->create())->get(route('search.index', ['q' => '  ']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('search/Index')
            ->where('query', '')
            ->where('results', null));

    expect($this->fakeProvider()->calls)->toBeEmpty();
});

it('narrows results to one type', function (): void {
    $this->fakeProvider()->searchResults = FakeProviderAdapter::aCatalogResults();
    $this->fakeProvider()->albums['MPREb_LJSVi8szMQL'] = FakeProviderAdapter::aCatalogAlbum();

    $this->actingAs(User::factory()->create())->get(route('search.index', ['q' => 'Zeit', 'type' => 'albums']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('type', 'albums')->loadDeferredProps(fn (AssertableInertia $reload) => $reload->has('results')));

    expect($this->fakeProvider()->calls[0]['filter'])->toBe(CatalogFilter::Albums);
});

it('rejects an unknown type', function (): void {
    $this->actingAs(User::factory()->create())->get(route('search.index', ['q' => 'Zeit', 'type' => 'podcasts']))
        ->assertSessionHasErrors('type');
});

it('returns palette suggestions as json', function (): void {
    $this->fakeProvider()->searchResults = FakeProviderAdapter::aCatalogResults();

    $this->actingAs(User::factory()->create())->getJson(route('search.suggestions', ['q' => 'Rammstein']))
        ->assertOk()
        ->assertJsonPath('topArtist.name', 'Rammstein')
        ->assertJsonCount(1, 'tracks')
        ->assertJsonCount(1, 'albums')
        ->assertJsonCount(1, 'artists')
        ->assertJsonCount(0, 'playlists');
});

it('caps suggestions per group', function (): void {
    $results = FakeProviderAdapter::aCatalogResults();
    $this->fakeProvider()->searchResults = new App\Services\Music\Data\CatalogResults(
        $results->topArtist,
        array_fill(0, 6, $results->tracks[0]),
        array_fill(0, 5, $results->albums[0]),
        array_fill(0, 4, $results->artists[0]),
        [],
    );

    $this->actingAs(User::factory()->create())->getJson(route('search.suggestions', ['q' => 'Rammstein']))
        ->assertJsonCount(3, 'tracks')
        ->assertJsonCount(2, 'albums')
        ->assertJsonCount(1, 'artists');
});

it('marks tracks already in the library', function (): void {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for(YouTubeMusicAccount::factory()->for($user), 'youtubeMusicAccount')->create();
    $playlist->tracks()->create(['youtube_video_id' => 'xXp4GnC1Z3Q', 'title' => 'Rendezvous', 'artists' => 'Ludwig Göransson']);
    $this->fakeProvider()->searchResults = FakeProviderAdapter::aCatalogResults();

    $this->actingAs($user)->getJson(route('search.suggestions', ['q' => 'Rendezvous']))
        ->assertJsonPath('tracks.0.inLibrary', true);

    $this->actingAs(User::factory()->create())->getJson(route('search.suggestions', ['q' => 'Rendezvous']))
        ->assertJsonPath('tracks.0.inLibrary', false);
});

it('lists the most recent library tracks by the top artist', function (): void {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for(YouTubeMusicAccount::factory()->for($user), 'youtubeMusicAccount')->create();

    foreach (['Old', 'Mid', 'New', 'Newest'] as $position => $title) {
        $this->travel(1)->minutes();
        $playlist->tracks()->create(['youtube_video_id' => 'video00000'.$position, 'title' => $title, 'artists' => 'Rammstein']);
    }

    $playlist->tracks()->create(['youtube_video_id' => 'video000009', 'title' => 'Other', 'artists' => 'Someone Else']);
    $this->fakeProvider()->searchResults = FakeProviderAdapter::aCatalogResults();

    $this->actingAs($user)->getJson(route('search.suggestions', ['q' => 'Rammstein']))
        ->assertJsonPath('topArtistInLibrary.trackCount', 4)
        ->assertJsonPath('topArtistLibraryTracks.0.title', 'Newest')
        ->assertJsonCount(3, 'topArtistLibraryTracks');
});

it('shows a catalog error instead of failing', function (): void {
    $this->fakeProvider()->unavailable = true;

    $this->actingAs(User::factory()->create())->get(route('search.index', ['q' => 'Rammstein']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('results', null)
                ->where('catalogError', fn ($message): bool => is_string($message) && $message !== '')));
});

it('renders a catalog error when the provider budget is spent', function (): void {
    $this->fakeProvider()->rateLimitedFor = 30;

    $this->actingAs(User::factory()->create())->get(route('search.index', ['q' => 'Rammstein']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('catalogError', fn ($message): bool => is_string($message) && str_contains($message, '30'))));
});

it('answers suggestions with a 503 when the provider is down', function (): void {
    $this->fakeProvider()->unavailable = true;

    $this->actingAs(User::factory()->create())->getJson(route('search.suggestions', ['q' => 'Rammstein']))
        ->assertStatus(503)
        ->assertJsonStructure(['message']);
});

it('requires authentication', function (string $route): void {
    $this->get($route)->assertRedirect(route('login'));
})->with([
    fn () => route('search.index'),
    fn () => route('search.suggestions'),
    fn () => route('artist.show', 'UC1'),
    fn () => route('album.show', 'MPREb_1'),
    fn () => route('browse-playlist.show', 'PL1'),
]);

it('throttles suggestions per user', function (): void {
    RateLimiter::clear('catalog-suggestions');
    $this->fakeProvider()->searchResults = FakeProviderAdapter::aCatalogResults();
    $user = User::factory()->create();

    foreach (range(1, 120) as $attempt) {
        $this->actingAs($user)->getJson(route('search.suggestions', ['q' => 'Rammstein']))->assertOk();
    }

    $this->actingAs($user)->getJson(route('search.suggestions', ['q' => 'Rammstein']))->assertTooManyRequests();
    $this->actingAs(User::factory()->create())->getJson(route('search.suggestions', ['q' => 'Rammstein']))->assertOk();
});
