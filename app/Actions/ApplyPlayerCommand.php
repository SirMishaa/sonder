<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PlayerCommandData;
use App\Data\PlayerCommandItemData;
use App\Enums\ListenOrigin;
use App\Enums\PlayerCommandAction;
use App\Enums\PlayerCommandKind;
use App\Models\PlayerQueue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Applies an extension command to the user's stored queue, so a Sonder tab
 * opened later starts from it. An open tab replays the same command itself
 * and its next save supersedes this one.
 *
 * @phpstan-type QueueTrack array{key: string, videoId: string|null, title: string, artists: string, album: string|null, duration: string|null, durationSeconds: int, thumbnailUrl: string|null, playlistId: string|null, queued?: bool, genres?: list<string>}
 */
final readonly class ApplyPlayerCommand
{
    /**
     * Returns the stored queue's new version.
     */
    public function handle(User $user, PlayerCommandData $command): int
    {
        return DB::transaction(function () use ($user, $command): int {
            $queue = PlayerQueue::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            /** @var list<QueueTrack> $tracks */
            $tracks = array_values($queue->tracks ?? []);
            $index = $queue === null ? -1 : $queue->current_index;
            $source = $queue?->source;
            $origin = $queue->origin ?? ListenOrigin::Queue;
            $items = $this->queueTracks($command);

            if ($command->action === PlayerCommandAction::PlayNow && $command->kind === PlayerCommandKind::Mix) {
                $tracks = $items;
                $index = 0;
                $source = ['playlistId' => $command->listId, 'title' => $command->title];
                $origin = ListenOrigin::Playlist;
            } elseif ($command->action === PlayerCommandAction::PlayNow) {
                array_splice($tracks, $index + 1, 0, $items);
                $index++;
                $source ??= ['playlistId' => null, 'title' => $command->title];
                $origin = ListenOrigin::Queue;
            } else {
                $queued = array_map(fn (array $track): array => [...$track, 'queued' => true], $items);
                array_splice($tracks, $this->insertionPoint($tracks, $index, $command->action), 0, $queued);

                if ($index < 0) {
                    $index = 0;
                    $source = ['playlistId' => $command->listId, 'title' => $command->title];
                }
            }

            [$tracks, $index] = $this->capped($tracks, $index, array_column($items, 'key'));
            $version = ($queue->version ?? 0) + 1;

            PlayerQueue::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'tracks' => $tracks,
                    'current_index' => $index,
                    'source' => $source,
                    'origin' => $origin,
                    'version' => $version,
                ],
            );

            return $version;
        });
    }

    /**
     * @return list<QueueTrack>
     */
    private function queueTracks(PlayerCommandData $command): array
    {
        $now = (int) now()->getTimestampMs();

        return array_map(fn (PlayerCommandItemData $item, int $position): array => [
            'key' => "ext:{$item->videoId}#{$position}@{$now}",
            'videoId' => $item->videoId,
            'title' => $item->title,
            'artists' => $item->artists,
            'album' => null,
            'duration' => null,
            'durationSeconds' => $item->durationSeconds,
            'thumbnailUrl' => $item->thumbnailUrl,
            'playlistId' => null,
            'queued' => false,
            'genres' => [],
        ], $command->items, array_keys($command->items));
    }

    /**
     * Play next goes right after the current track; enqueue goes behind the
     * tracks already queued by hand.
     *
     * @param  list<QueueTrack>  $tracks
     */
    private function insertionPoint(array $tracks, int $index, PlayerCommandAction $action): int
    {
        $at = $index + 1;

        if ($action === PlayerCommandAction::Enqueue) {
            while (($tracks[$at]['queued'] ?? false) === true) {
                $at++;
            }
        }

        return $at;
    }

    /**
     * Drops the oldest played tracks first, then the last upcoming ones,
     * never the current track nor the tracks this command added, to stay
     * within what the browser keeps.
     *
     * @param  list<QueueTrack>  $tracks
     * @param  list<string>  $added  keys of the tracks this command added
     * @return array{list<QueueTrack>, int}
     */
    private function capped(array $tracks, int $index, array $added): array
    {
        $excess = count($tracks) - PlayerQueue::MAX_TRACKS;

        if ($excess <= 0) {
            return [$tracks, $index];
        }

        $played = min($excess, max(0, $index));
        $tracks = array_slice($tracks, $played);
        $index -= $played;
        $excess -= $played;
        $kept = array_flip($added);

        for ($at = count($tracks) - 1; $excess > 0 && $at > $index; $at--) {
            if (! isset($kept[$tracks[$at]['key']])) {
                array_splice($tracks, $at, 1);
                $excess--;
            }
        }

        return [$tracks, $index];
    }
}
