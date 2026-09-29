<?php

declare(strict_types=1);

use App\Actions\SummarizeLibrary;
use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;

it('summarizes the current library from its synced tracks', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $focus = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();
    $gaming = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();
    $gone = Playlist::factory()->for($account, 'youtubeMusicAccount')->removed()->create();

    $focus->tracks()->createMany([
        ['youtube_video_id' => 'V1', 'title' => 'Sonne', 'artists' => 'Rammstein', 'duration_seconds' => 3600],
        ['youtube_video_id' => 'V2', 'title' => 'Zeit', 'artists' => 'Rammstein, Guest', 'duration_seconds' => 1800],
        ['youtube_video_id' => 'V3', 'title' => 'The Kill', 'artists' => 'Thirty Seconds To Mars', 'duration_seconds' => 1800],
    ]);
    $gaming->tracks()->create(['youtube_video_id' => 'V1', 'title' => 'Sonne', 'artists' => 'Rammstein', 'duration_seconds' => 3600]);
    $gone->tracks()->create(['youtube_video_id' => 'V9', 'title' => 'Old', 'artists' => 'Forgotten', 'duration_seconds' => 7200]);

    $stats = resolve(SummarizeLibrary::class)->handle($account);

    expect($stats->playlistCount)->toBe(2)
        ->and($stats->trackCount)->toBe(3)
        ->and($stats->artistCount)->toBe(2)
        ->and($stats->totalHours)->toBe(2)
        ->and($stats->topArtists[0]->name)->toBe('Rammstein')
        ->and($stats->topArtists[0]->trackCount)->toBe(2);
});
