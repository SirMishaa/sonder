<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Listen;
use App\Models\User;
use Carbon\CarbonImmutable;
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;

final readonly class RecordListen
{
    /**
     * Store a listen. The client generates the id, so a listen sent twice
     * (page unload racing a track change) is stored once.
     *
     * @param  array{id: string, youtube_video_id: string, title: string, artists: string, youtube_playlist_id?: string|null, origin: string, end_reason: string, started_at: string, ended_at: string, position_seconds: int, listened_seconds: int, duration_seconds?: int|null}  $attributes
     */
    public function handle(User $user, array $attributes): void
    {
        $now = now();

        $inserted = Listen::query()->insertOrIgnore([
            'id' => $attributes['id'],
            'user_id' => $user->id,
            'youtube_video_id' => $attributes['youtube_video_id'],
            'title' => $attributes['title'],
            'artists' => $attributes['artists'],
            'youtube_playlist_id' => $attributes['youtube_playlist_id'] ?? null,
            'origin' => $attributes['origin'],
            'end_reason' => $attributes['end_reason'],
            'started_at' => CarbonImmutable::parse($attributes['started_at']),
            'ended_at' => CarbonImmutable::parse($attributes['ended_at']),
            'position_seconds' => $attributes['position_seconds'],
            'listened_seconds' => $attributes['listened_seconds'],
            'duration_seconds' => $attributes['duration_seconds'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return;
        }

        Meter::counter('sonder.listens', '{listen}', 'Listens recorded, by end reason and origin')
            ->add(1, ['end_reason' => $attributes['end_reason'], 'origin' => $attributes['origin']]);
    }
}
