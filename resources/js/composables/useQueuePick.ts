import { usePlayer } from '@/composables/usePlayer';

/**
 * Click behavior of an up-next list. The first entry plays on one click;
 * any later one moves up to play next, and a double click plays it at once.
 */
export function useQueuePick() {
    const player = usePlayer();
    let movedKey: string | null = null;

    /** @param offset the entry's place in the up-next list, from 0 */
    function pick(event: MouseEvent, offset: number): void {
        if (event.detail > 1) {
            if (movedKey !== null) {
                const key = movedKey;
                movedKey = null;
                player.jumpTo(
                    player.state.queue.findIndex((item) => item.key === key),
                );
            }

            return;
        }

        movedKey = null;
        const index = player.state.index + 1 + offset;

        if (offset === 0) {
            player.jumpTo(index);

            return;
        }

        movedKey = player.state.queue[index]?.key ?? null;
        player.bringUpNext(index);
    }

    return { pick };
}
