type SharedStorage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

type Mark = { id: string; at: number };

const FOCUS_KEY = 'sonder:tab-focus';
const PLAYING_KEY = 'sonder:tab-playing';

export type TabLeader = {
    id: string;
    /** Marks this tab as the most recently focused one. */
    touch(): void;
    /** Tells the other tabs whether this one is playing. */
    setPlaying(playing: boolean): void;
    /** Whether this tab should replay commands sent to all of them. */
    isLeader(): boolean;
};

/**
 * Elects one Sonder tab among those open in this browser, through
 * localStorage: the tab that is playing leads; with none playing, the most
 * recently focused one does. Without shared storage, every tab leads alone.
 */
export function createTabLeader(
    deps: { storage?: SharedStorage; now?: () => number; id?: string } = {},
): TabLeader {
    const storage = deps.storage ?? window.localStorage;
    const now = deps.now ?? (() => Date.now());
    const id = deps.id ?? crypto.randomUUID();

    function read(key: string): Mark | null {
        const value = storage.getItem(key);

        return value === null ? null : (JSON.parse(value) as Mark);
    }

    function write(key: string): void {
        try {
            storage.setItem(key, JSON.stringify({ id, at: now() }));
        } catch {
            // Storage unavailable: this tab leads alone.
        }
    }

    const leader: TabLeader = {
        id,
        touch: () => write(FOCUS_KEY),
        setPlaying(playing) {
            if (playing) {
                write(PLAYING_KEY);

                return;
            }

            try {
                if (read(PLAYING_KEY)?.id === id) {
                    storage.removeItem(PLAYING_KEY);
                }
            } catch {
                // Storage unavailable: nothing to clear.
            }
        },
        isLeader() {
            try {
                const playing = read(PLAYING_KEY);

                return playing !== null
                    ? playing.id === id
                    : (read(FOCUS_KEY)?.id ?? id) === id;
            } catch {
                return true;
            }
        },
    };

    leader.touch();

    return leader;
}
