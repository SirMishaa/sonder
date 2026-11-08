<?php

declare(strict_types=1);

use App\Enums\ArtistRole;
use App\Services\Music\YouTubeMusic\YouTubeMusicMapper;

$fixture = fn (string $name): mixed => json_decode(
    (string) file_get_contents(__DIR__.'/../../../../Fixtures/YouTubeMusic/'.$name.'.json'),
    false,
    flags: JSON_THROW_ON_ERROR,
);

it('maps the top result artist from its artists list', function () use ($fixture): void {
    $results = (new YouTubeMusicMapper())->searchResults($fixture('catalog-search'));

    expect($results->topArtist?->ref->externalId)->toBe('UCs6GGpd9zvsYghuYe0VDFUQ')
        ->and($results->topArtist?->name)->toBe('Rammstein')
        ->and($results->topArtist?->subscribers)->toBe('43.3M');
});

it('keeps songs, albums, artists and playlists and drops episodes, podcasts and videos', function () use ($fixture): void {
    $results = (new YouTubeMusicMapper())->searchResults($fixture('catalog-search'));

    expect($results->tracks)->toHaveCount(6)
        ->and($results->albums)->toHaveCount(3)
        ->and($results->albums[0]->year)->toBe(1997)
        ->and($results->albums[0]->artists[0]->name)->toBe('Rammstein')
        ->and($results->artists)->toHaveCount(2)
        ->and($results->artists[0]->name)->toBe('Lindemann')
        ->and($results->playlists)->toHaveCount(3)
        ->and($results->playlists[0]->ref->externalId)->toBe('RDCLAK5uy_nP1U38z5TIWBgTTiXDBgO_3DaHSaotjbc');
});

it('credits top result card songs to the top artist', function () use ($fixture): void {
    $tracks = (new YouTubeMusicMapper())->searchResults($fixture('catalog-search'))->tracks;

    expect($tracks[0]->title)->toBe('Engel (Live in Mexico City)')
        ->and($tracks[0]->durationSeconds)->toBe(275)
        ->and($tracks[0]->artistNames())->toBe('Rammstein')
        ->and($tracks[0]->artists[0]->ref?->externalId)->toBe('UCs6GGpd9zvsYghuYe0VDFUQ')
        ->and($tracks[0]->artists[0]->role)->toBe(ArtistRole::Main)
        ->and($tracks[1]->artistNames())->toBe('Rammstein')
        ->and($tracks[2]->artistNames())->toBe('Rammstein');
});

it('leaves an empty-artist song outside the top card uncredited', function (): void {
    $song = fn (string $title, array $artists): stdClass => (object) [
        'resultType' => 'song',
        'title' => $title,
        'videoId' => 'v-'.$title,
        'artists' => $artists,
    ];

    $results = (new YouTubeMusicMapper())->searchResults([
        (object) [
            'resultType' => 'artist',
            'category' => 'Top result',
            'artists' => [(object) ['name' => 'Apashe', 'id' => 'UCapashe']],
        ],
        $song('Card song', []),
        $song('Credited card song', [(object) ['name' => 'Other', 'id' => 'UCother']]),
        (object) ['resultType' => 'album', 'title' => 'An album', 'browseId' => 'MPREb_1', 'artists' => []],
        $song('Later song', []),
    ]);

    expect($results->tracks[0]->artistNames())->toBe('Apashe')
        ->and($results->tracks[1]->artistNames())->toBe('Other')
        ->and($results->tracks[2]->artists)->toBe([]);
});

it('maps a search song without album to a null album', function () use ($fixture): void {
    expect((new YouTubeMusicMapper())->searchResults($fixture('catalog-search'))->tracks[0]->album)->toBeNull();
});

it('returns empty sections for a query with only episodes', function () use ($fixture): void {
    $episodes = array_values(array_filter(
        $fixture('catalog-search'),
        fn (stdClass $hit): bool => in_array($hit->resultType, ['episode', 'podcast'], true),
    ));

    expect($episodes)->not->toBeEmpty()
        ->and((new YouTubeMusicMapper())->searchResults($episodes)->isEmpty())->toBeTrue();
});

it('maps an artist page', function () use ($fixture): void {
    $artist = (new YouTubeMusicMapper())->artist($fixture('catalog-artist'), 'UCs6GGpd9zvsYghuYe0VDFUQ');

    expect($artist->summary->name)->toBe('Rammstein')
        ->and($artist->monthlyListeners)->toBe('43.3M')
        ->and($artist->views)->toBe(8401443283)
        ->and($artist->topTracks)->toHaveCount(5)
        ->and($artist->topTracks[0]->durationSeconds)->toBeNull()
        ->and($artist->topTracks[0]->album?->title)->toBe('Mutter')
        ->and($artist->albums[0]->year)->toBe(2022)
        ->and($artist->singles[0]->type)->toBe('Single')
        ->and($artist->videos[0]->views)->toBe(312000000)
        ->and($artist->related[0]->name)->toBe('OOMPH!');
});

it('maps an album with its plays', function () use ($fixture): void {
    $album = (new YouTubeMusicMapper())->album($fixture('catalog-album'), 'MPREb_LJSVi8szMQL');

    expect($album->summary->title)->toBe('Zeit')
        ->and($album->trackCount)->toBe(11)
        ->and($album->durationSeconds)->toBe(2656)
        ->and($album->playsByVideoId['t-NUBR2kufw'])->toBe(36000000)
        ->and($album->tracks[0]->album?->ref?->externalId)->toBe('MPREb_LJSVi8szMQL');
});

it('skips non-object search hits', function (): void {
    $results = (new YouTubeMusicMapper())->searchResults([
        null,
        'oops',
        (object) ['resultType' => 'song', 'title' => 'Sonne', 'videoId' => 'v1'],
    ]);

    expect($results->tracks)->toHaveCount(1);
});

it('parses counts', function (string $text, ?int $expected): void {
    expect((new YouTubeMusicMapper())->artist((object) ['views' => $text], 'UC1')->views)->toBe($expected);
})->with([
    ['36M plays', 36000000],
    ['8,401,443,283 views', 8401443283],
    ['362K', 362000],
    ['50 beats', 50],
    ['12 monthly listeners', 12],
    ['1.5M views', 1500000],
    ['no views', null],
]);
