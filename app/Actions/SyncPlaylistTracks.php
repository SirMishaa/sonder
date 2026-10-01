<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Playlist;
use App\Models\Track;
use App\Services\Music\Data\RemotePlaylist;
use App\Services\Music\Data\RemoteTrack;
use App\Services\Thumbnails\ThumbnailProxy;
use Carbon\CarbonInterval;
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
    public function handle(Playlist $playlist, RemotePlaylist $data): bool
    {
        return DB::transaction(function () use ($playlist, $data): bool {
            $changed = false;

            $playlist->fill(['duration' => $this->playlistDuration($data)]);

            if ($playlist->isDirty()) {
                $changed = true;
            }

            $stored = $this->keyed($playlist->tracks()->get()->all(), fn (Track $track): array => [
                $track->youtube_video_id,
                $track->title,
                $track->artists,
            ]);

            $incoming = $this->keyed($data->tracks, fn (RemoteTrack $track): array => [
                $track->ref?->externalId,
                $track->title,
                $track->artistNames(),
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
    private function attributes(RemoteTrack $track, int $position): array
    {
        return [
            'youtube_video_id' => $track->ref?->externalId,
            'title' => $track->title,
            'artists' => $track->artistNames(),
            'album' => $track->album?->title,
            'duration' => $track->durationSeconds === null ? null : $this->clock($track->durationSeconds),
            'duration_seconds' => $track->durationSeconds,
            'thumbnail_url' => ThumbnailProxy::url($track->thumbnailUrl),
            'is_explicit' => $track->isExplicit,
            'is_available' => $track->isAvailable,
            'position' => $position,
        ];
    }

    /**
     * "3:19", or "1:02:03" past an hour, as YouTube Music displays it.
     */
    private function clock(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $clock = sprintf('%d:%02d', intdiv($seconds % 3600, 60), $seconds % 60);

        return $hours > 0 ? sprintf('%d:%02d:%02d', $hours, intdiv($seconds % 3600, 60), $seconds % 60) : $clock;
    }

    /**
     * Total length ("48m", "1h 12m"), always in English like the label
     * YouTube Music used to give, so the stored value never depends on the
     * locale of whoever triggered the sync. Null when no track reports a
     * duration.
     */
    private function playlistDuration(RemotePlaylist $data): ?string
    {
        $total = array_sum(array_map(fn (RemoteTrack $track): int => $track->durationSeconds ?? 0, $data->tracks));

        return $total === 0 ? null : CarbonInterval::seconds($total)->cascade()->locale('en')->forHumans(['short' => true, 'parts' => 2]);
    }
}
