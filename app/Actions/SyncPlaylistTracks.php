<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PlaylistData;
use App\Data\TrackData;
use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Support\Facades\DB;

/**
 * Brings a stored playlist's tracks in line with what YouTube Music returned,
 * touching only the rows that actually differ.
 *
 * Tracks are matched by video id rather than rebuilt, so a track that is still
 * in the playlist keeps its row (and its id) across syncs. A playlist can hold
 * the same video more than once, so each occurrence is matched in order; a
 * track with no video id falls back to its title and artists.
 */
final readonly class SyncPlaylistTracks
{
    /**
     * @return bool Whether anything about the playlist changed.
     */
    public function handle(Playlist $playlist, PlaylistData $data): bool
    {
        return DB::transaction(function () use ($playlist, $data): bool {
            $changed = false;

            $playlist->fill(['duration' => $data->duration]);

            if ($playlist->isDirty()) {
                $changed = true;
            }

            $stored = $this->keyed($playlist->tracks()->get()->all(), fn (Track $track): array => [
                $track->youtube_video_id,
                $track->title,
                $track->artists,
            ]);

            $incoming = $this->keyed($data->tracks, fn (TrackData $track): array => [
                $track->videoId,
                $track->title,
                $track->artists,
            ]);

            $position = 0;

            foreach ($incoming as $key => $track) {
                $attributes = $this->attributes($track, $position++);
                $existing = $stored[$key] ?? null;
                unset($stored[$key]);

                if ($existing === null) {
                    $playlist->tracks()->create($attributes);
                    $changed = true;

                    continue;
                }

                $existing->fill($attributes);

                if ($existing->isDirty()) {
                    $existing->save();
                    $changed = true;
                }
            }

            foreach ($stored as $removed) {
                $removed->delete();
                $changed = true;
            }

            if ($changed) {
                $playlist->last_changed_at = now();
            }

            $playlist->save();

            return $changed;
        });
    }

    /**
     * Keys each item by its identity plus its occurrence number, so the second
     * copy of a video only ever matches the second stored copy.
     *
     * @template T
     *
     * @param  array<int, T>  $items
     * @param  callable(T): array{0: ?string, 1: string, 2: string}  $identity
     * @return array<string, T>
     */
    private function keyed(array $items, callable $identity): array
    {
        $keyed = [];
        $occurrences = [];

        foreach ($items as $item) {
            [$videoId, $title, $artists] = $identity($item);

            $base = $videoId ?? 'untitled:'.hash('xxh128', $title."\n".$artists);
            $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;

            $keyed[$base.'#'.$occurrences[$base]] = $item;
        }

        return $keyed;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(TrackData $track, int $position): array
    {
        return [
            'youtube_video_id' => $track->videoId,
            'title' => $track->title,
            'artists' => $track->artists,
            'album' => $track->album,
            'duration' => $track->duration,
            'duration_seconds' => $track->durationSeconds,
            'thumbnail_url' => $track->thumbnailUrl,
            'is_explicit' => $track->isExplicit,
            'is_available' => $track->isAvailable,
            'position' => $position,
        ];
    }
}
