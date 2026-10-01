import { usePlayer } from '@/composables/usePlayer';
import type { QueueSource } from '@/composables/usePlayer';
import { flyToQueue } from '@/lib/queueFlight';
import type { Flight } from '@/lib/queueFlight';

/**
 * Click behavior shared by every clickable track. With nothing playing a
 * click plays it; while something plays, one click queues it next (with the
 * flight to "Up next") and a double click replaces the current track.
 * Clicking the track already playing pauses or resumes it.
 */
export function useTrackTap(source: () => QueueSource) {
    const player = usePlayer();
    let flight: Flight | null = null;
    let queuedKey: string | null = null;

    /**
     * @param position the track's place in its list, which keys it in the queue
     * @param play replaces what is playing with the track
     */
    function tap(
        event: MouseEvent,
        track: App.Data.TrackData,
        position: number,
        play: () => void,
    ): void {
        if (event.detail > 1) {
            if (queuedKey !== null) {
                flight?.cancel();
                player.unqueue(queuedKey);
                queuedKey = null;
                play();
            }

            return;
        }

        queuedKey = null;
        const playing = player.current.value;

        if (!playing) {
            play();

            return;
        }

        if (track.videoId !== null && playing.videoId === track.videoId) {
            player.toggle();

            return;
        }

        const row = event.currentTarget as HTMLElement;
        queuedKey = player.queueNext(track, position, source());
        flight = flyToQueue(
            row,
            row.querySelector<HTMLElement>('[data-track-artwork]'),
        );
    }

    /** Stops a double click from selecting the row's text. */
    function preventSelection(event: MouseEvent): void {
        if (event.detail > 1) {
            event.preventDefault();
        }
    }

    return { tap, preventSelection };
}
