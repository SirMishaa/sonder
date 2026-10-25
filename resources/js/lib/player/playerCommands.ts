import type { Player } from '@/composables/usePlayer';

/** What the `player.command` broadcast carries. */
export type PlayerCommandPayload = App.Data.PlayerCommandData & {
    id: string;
    traceparent: string | null;
};

function toTrack(item: App.Data.PlayerCommandItemData): App.Data.TrackData {
    return {
        videoId: item.videoId,
        title: item.title,
        artists: item.artists,
        album: null,
        duration: null,
        durationSeconds: item.durationSeconds,
        thumbnailUrl: item.thumbnailUrl,
        isExplicit: false,
        isAvailable: true,
        genres: [],
    };
}

/**
 * Replays a command sent from the browser extension on this tab's player,
 * through the same functions the interface uses.
 */
export function applyPlayerCommand(
    player: Player,
    command: PlayerCommandPayload,
): void {
    const tracks = command.items.map(toTrack);
    const source = { playlistId: command.listId, title: command.title };

    if (command.action === 'play_now' && command.kind === 'mix') {
        player.playTracks(tracks, 0, source, 'playlist');
    } else if (command.action === 'play_now') {
        player.playNext(tracks[0]!, source, 'queue');
    } else if (command.action === 'play_next') {
        [...tracks]
            .reverse()
            .forEach((track, position) =>
                player.queueFirst(track, position, source),
            );
    } else {
        tracks.forEach((track, position) =>
            player.queueNext(track, position, source),
        );
    }

    if (command.traceparent) {
        player.traceNextSave(command.traceparent);
    }
}
