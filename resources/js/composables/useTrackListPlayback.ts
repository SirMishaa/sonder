import { computed } from 'vue';
import { usePlayer } from '@/composables/usePlayer';
import type { QueueSource } from '@/composables/usePlayer';
import { useTrackTap } from '@/composables/useTrackTap';

/**
 * Playback wiring for a page's track list: play from a track, shuffle the
 * whole list, the click behavior of useTrackTap, and which row is playing.
 * Positions are indexes into `tracks()`.
 */
export function useTrackListPlayback(
    tracks: () => App.Data.TrackData[],
    source: () => QueueSource,
    origin: App.Enums.ListenOrigin,
) {
    const player = usePlayer();
    const { tap, preventSelection } = useTrackTap(source);

    const playable = computed(() =>
        tracks().some((track) => track.isAvailable),
    );

    function isCurrent(index: number): boolean {
        const track = tracks()[index];

        return (
            track !== undefined &&
            player.isCurrentTrack(source().playlistId, track, index)
        );
    }

    function play(index = 0): void {
        player.playTracks(tracks(), index, source(), origin);
    }

    function shuffle(): void {
        player.playShuffled(tracks(), source(), origin);
    }

    function onTrackTap(event: MouseEvent, index: number): void {
        const track = tracks()[index];

        if (track?.isAvailable) {
            tap(event, track, index, () => play(index));
        }
    }

    return {
        player,
        playable,
        isCurrent,
        play,
        shuffle,
        onTrackTap,
        preventSelection,
    };
}
