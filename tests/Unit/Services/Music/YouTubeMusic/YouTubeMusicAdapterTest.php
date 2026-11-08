<?php

declare(strict_types=1);

use App\Enums\CatalogFilter;
use App\Enums\Provider;
use App\Exceptions\Providers\CredentialsRejected;
use App\Services\Music\Contracts\ProviderCredentials;
use App\Services\Music\YouTubeMusic\YouTubeMusicAdapter;
use App\Services\Music\YouTubeMusic\YouTubeMusicCredentials;
use App\Services\Music\YouTubeMusic\YouTubeMusicMapper;
use Tests\Support\FakeYouTubeMusicGateway;

beforeEach(function (): void {
    $this->gateway = new FakeYouTubeMusicGateway();
    $this->adapter = new YouTubeMusicAdapter($this->gateway, new YouTubeMusicMapper());
    $this->credentials = new YouTubeMusicCredentials('SID=abc');
});

it('verifies the account with a live call', function (): void {
    $account = $this->adapter->account($this->credentials);

    expect($account->ref?->externalId)->toBe('UC123')
        ->and($this->gateway->calls[0])->toBe(['method' => 'account', 'cookie' => 'SID=abc']);
});

it('treats an empty raw library as a signed-out session', function (): void {
    $this->adapter->playlists($this->credentials);
})->throws(CredentialsRejected::class);

it('accepts a library holding only system playlists', function (): void {
    $this->gateway->library = [(object) ['playlistId' => 'SE', 'title' => 'Episodes for Later']];

    expect($this->adapter->playlists($this->credentials))->toBe([]);
});

it('lists the playlists, system ones left out and Liked Music kept', function (): void {
    $this->gateway->library = [
        (object) ['playlistId' => 'LM', 'title' => 'Liked Music'],
        (object) ['playlistId' => 'SE', 'title' => 'Episodes for Later'],
        (object) ['playlistId' => 'PL1', 'title' => 'Deep Focus'],
    ];

    $ids = array_map(fn ($summary) => $summary->ref->externalId, $this->adapter->playlists($this->credentials));

    expect($ids)->toBe(['LM', 'PL1']);
});

it('reads a playlist with the listing count as hint', function (): void {
    $this->gateway->playlists['PL1'] = (object) ['title' => 'Deep Focus', 'tracks' => []];

    expect($this->adapter->playlist($this->credentials, 'PL1', 7)->summary->trackCount)->toBe(7);
});

it('searches the catalog without a cookie', function (): void {
    $this->gateway->searches['rammstein'] = [(object) ['resultType' => 'song', 'title' => 'Sonne', 'videoId' => 'v1']];

    $results = $this->adapter->search('rammstein', CatalogFilter::Tracks);

    expect($results->tracks[0]->title)->toBe('Sonne')
        ->and($this->gateway->calls[0])->toMatchArray(['method' => 'search', 'cookie' => '', 'id' => 'rammstein']);
});

it('reads an album', function (): void {
    $this->gateway->albums['MPREb1'] = (object) ['title' => 'Zeit', 'trackCount' => 11, 'tracks' => []];

    expect($this->adapter->album('MPREb1')->trackCount)->toBe(11);
});

it('refuses credentials of another provider', function (): void {
    $foreign = new class implements ProviderCredentials
    {
        public static function fromArray(array $values): static
        {
            return new self();
        }

        public function provider(): Provider
        {
            return Provider::YouTubeMusic;
        }

        public function toArray(): array
        {
            return [];
        }
    };

    $this->adapter->account($foreign);
})->throws(LogicException::class);

it('never prints the cookie when dumped', function (): void {
    expect(print_r($this->credentials, true))->not->toContain('SID=abc');
});
