<?php

declare(strict_types=1);

use App\Enums\ArtistRole;
use App\Enums\Provider;
use App\Enums\SourceKind;
use App\Exceptions\Providers\CredentialsRejected;
use App\Services\Music\YouTubeMusic\YouTubeMusicMapper;

$mapper = fn (): YouTubeMusicMapper => new YouTubeMusicMapper();

it('maps a track', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'videoId' => 'xXp4GnC1Z3Q',
        'title' => 'The Mandalorian',
        'artists' => [(object) ['name' => 'Ludwig Göransson', 'id' => 'UCa'], (object) ['name' => 'Joseph Shirley']],
        'album' => (object) ['name' => 'Season 1', 'id' => 'MPREb1'],
        'duration_seconds' => 199,
        'isExplicit' => true,
        'isAvailable' => true,
        'videoType' => 'MUSIC_VIDEO_TYPE_ATV',
    ]);

    expect($track?->ref?->externalId)->toBe('xXp4GnC1Z3Q')
        ->and($track?->ref?->provider)->toBe(Provider::YouTubeMusic)
        ->and($track?->title)->toBe('The Mandalorian')
        ->and($track?->artistNames())->toBe('Ludwig Göransson, Joseph Shirley')
        ->and($track?->artists[0]->ref?->externalId)->toBe('UCa')
        ->and($track?->artists[1]->ref)->toBeNull()
        ->and($track?->album?->title)->toBe('Season 1')
        ->and($track?->album?->ref?->externalId)->toBe('MPREb1')
        ->and($track?->durationSeconds)->toBe(199)
        ->and($track?->kind)->toBe(SourceKind::Audio)
        ->and($track?->isExplicit)->toBeTrue();
});

it('reads the source kind from the video type', function (?string $videoType, SourceKind $kind) use ($mapper): void {
    expect($mapper()->track((object) ['videoId' => 'v1', 'videoType' => $videoType])?->kind)->toBe($kind);
})->with([
    'audio track' => ['MUSIC_VIDEO_TYPE_ATV', SourceKind::Audio],
    'upload' => ['MUSIC_VIDEO_TYPE_PRIVATELY_OWNED_TRACK', SourceKind::Audio],
    'official video' => ['MUSIC_VIDEO_TYPE_OMV', SourceKind::Video],
    'user video' => ['MUSIC_VIDEO_TYPE_UGC', SourceKind::Video],
    'unknown' => [null, SourceKind::Audio],
]);

it('drops podcast episodes', function () use ($mapper): void {
    expect($mapper()->track((object) ['videoId' => 'e1', 'videoType' => 'MUSIC_VIDEO_TYPE_PODCAST_EPISODE']))->toBeNull();
});

it('marks artists named in a feat. clause as featured', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'videoId' => 'v1',
        'title' => 'Under Pressure (feat. David Bowie)',
        'artists' => [(object) ['name' => 'Queen'], (object) ['name' => 'David Bowie']],
    ]);

    expect(array_map(fn ($artist) => $artist->role, $track->artists ?? []))
        ->toBe([ArtistRole::Main, ArtistRole::Featured]);
});

it('keeps a track YouTube Music can no longer play, without a ref', function () use ($mapper): void {
    $track = $mapper()->track((object) ['title' => 'Gone', 'isAvailable' => false]);

    expect($track?->ref)->toBeNull()
        ->and($track?->isAvailable)->toBeFalse();
});

it('falls back when a track carries nothing usable', function () use ($mapper): void {
    $track = $mapper()->track((object) []);

    expect($track?->title)->toBe('Untitled track')
        ->and($track?->artistNames())->toBe('Unknown artist')
        ->and($track?->album)->toBeNull()
        ->and($track?->durationSeconds)->toBeNull()
        ->and($track?->isExplicit)->toBeFalse()
        ->and($track?->isAvailable)->toBeTrue();
});

it('ignores artist entries that carry no name', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'artists' => [(object) ['name' => 'Hans Zimmer'], (object) ['name' => '  '], 'not an object'],
    ]);

    expect($track?->artistNames())->toBe('Hans Zimmer');
});

it('keeps the raw thumbnail URL, picking the smallest one wide enough', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'thumbnails' => [
            (object) ['url' => 'https://img.test/small.jpg', 'width' => 60],
            (object) ['url' => 'https://img.test/right.jpg', 'width' => 240],
            (object) ['url' => 'https://img.test/huge.jpg', 'width' => 1080],
        ],
    ]);

    expect($track?->thumbnailUrl)->toBe('https://img.test/right.jpg');
});

it('falls back to the largest thumbnail when none is wide enough', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'thumbnails' => [
            (object) ['url' => 'https://img.test/tiny.jpg', 'width' => 32],
            (object) ['url' => 'https://img.test/biggest.jpg', 'width' => 120],
        ],
    ]);

    expect($track?->thumbnailUrl)->toBe('https://img.test/biggest.jpg');
});

it('survives thumbnails that are missing or malformed', function () use ($mapper): void {
    expect($mapper()->track((object) ['thumbnails' => []])?->thumbnailUrl)->toBeNull()
        ->and($mapper()->track((object) ['thumbnails' => 'nope'])?->thumbnailUrl)->toBeNull()
        ->and($mapper()->track((object) ['thumbnails' => [(object) ['width' => 240]]])?->thumbnailUrl)->toBeNull();
});

it('treats a zero track count as unknown rather than empty', function () use ($mapper): void {
    expect($mapper()->playlistSummary((object) ['playlistId' => 'PL1', 'count' => 0])?->trackCount)->toBeNull();
});

it('reads a track count exposed as a numeric string', function () use ($mapper): void {
    expect($mapper()->playlistSummary((object) ['playlistId' => 'PL1', 'count' => '42'])?->trackCount)->toBe(42);
});

it('skips a listed playlist without an id', function () use ($mapper): void {
    expect($mapper()->playlistSummary((object) ['title' => 'Ghost']))->toBeNull();
});

it('reads an author given either as an object or as a list', function () use ($mapper): void {
    expect($mapper()->playlistSummary((object) ['playlistId' => 'PL1', 'author' => (object) ['name' => 'Mishaa']])?->author)->toBe('Mishaa')
        ->and($mapper()->playlistSummary((object) ['playlistId' => 'PL1', 'author' => [(object) ['name' => 'Mishaa']]])?->author)->toBe('Mishaa')
        ->and($mapper()->playlistSummary((object) ['playlistId' => 'PL1'])?->author)->toBeNull();
});

it('maps a playlist and its tracks, leaving podcast episodes out', function () use ($mapper): void {
    $playlist = $mapper()->playlist((object) [
        'title' => 'Deep Focus',
        'description' => 'No lyrics',
        'trackCount' => 2,
        'tracks' => [
            (object) ['videoId' => 'a', 'title' => 'One'],
            (object) ['videoId' => 'b', 'title' => 'Two'],
            (object) ['videoId' => 'e', 'title' => 'Episode', 'videoType' => 'MUSIC_VIDEO_TYPE_PODCAST_EPISODE'],
            'not an object',
        ],
    ], 'PL1');

    expect($playlist->summary->ref->externalId)->toBe('PL1')
        ->and($playlist->summary->title)->toBe('Deep Focus')
        ->and($playlist->summary->trackCount)->toBe(2)
        ->and($playlist->tracks)->toHaveCount(2);
});

it('counts the tracks it received when the playlist reports no count', function () use ($mapper): void {
    expect($mapper()->playlist((object) ['tracks' => [(object) ['title' => 'One']]], 'PL1')->summary->trackCount)->toBe(1);
});

it('prefers the listing count over counting the tracks', function () use ($mapper): void {
    expect($mapper()->playlist((object) ['tracks' => []], 'PL1', trackCountHint: 300)->summary->trackCount)->toBe(300);
});

it('maps an account', function () use ($mapper): void {
    $account = $mapper()->account((object) ['name' => 'Mishaa', 'channelId' => 'UC123']);

    expect($account->displayName)->toBe('Mishaa')
        ->and($account->ref->externalId)->toBe('UC123');
});

it('names an account it cannot read', function () use ($mapper): void {
    expect($mapper()->account((object) ['channelId' => 'UC123'])->displayName)->toBe('Unknown account');
});

it('refuses an account without a channel id', function () use ($mapper): void {
    $mapper()->account((object) ['name' => 'Mishaa']);
})->throws(CredentialsRejected::class);
