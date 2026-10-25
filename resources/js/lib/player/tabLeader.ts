type SharedStorage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

type Tab = { seen: number; focusedAt: number; playing: boolean };

type Registry = Record<string, Tab>;

const KEY = 'sonder:tabs';

/** A tab that has not answered for this long is gone (closed, crashed, discarded). */
export const TAB_TIMEOUT_MS = 15_000;

/** How often an open tab says it is still there. */
export const HEARTBEAT_MS = 5_000;

export type TabLeader = {
    id: string;
    /** Marks this tab as the most recently focused one. */
    touch(): void;
    /** Tells the other tabs whether this one is playing. */
    setPlaying(playing: boolean): void;
    /** Says this tab is still open; call every HEARTBEAT_MS. */
    heartbeat(): void;
    /** Leaves the election, when the page goes away. */
    close(): void;
    /** Whether this tab should replay commands sent to all of them. */
    isLeader(): boolean;
};

/**
 * Elects one Sonder tab among those open in this browser, through a shared
 * registry in localStorage: among the tabs still answering, a playing one
 * leads; with none playing, the most recently focused one does. Tabs that
 * stop answering drop out after TAB_TIMEOUT_MS. Without shared storage,
 * every tab leads alone.
 */
export function createTabLeader(
    deps: { storage?: SharedStorage; now?: () => number; id?: string } = {},
): TabLeader {
    const storage = deps.storage ?? window.localStorage;
    const now = deps.now ?? (() => Date.now());
    const id = deps.id ?? crypto.randomUUID();

    function read(): Registry {
        const value = storage.getItem(KEY);

        return value === null ? {} : (JSON.parse(value) as Registry);
    }

    function update(change: (registry: Registry) => void): void {
        try {
            const registry = read();
            change(registry);
            storage.setItem(KEY, JSON.stringify(registry));
        } catch {
            // Storage unavailable: this tab leads alone.
        }
    }

    function mine(registry: Registry): Tab {
        registry[id] ??= { seen: now(), focusedAt: 0, playing: false };

        return registry[id];
    }

    const leader: TabLeader = {
        id,
        touch: () =>
            update((registry) => {
                Object.assign(mine(registry), {
                    seen: now(),
                    focusedAt: now(),
                });
            }),
        setPlaying: (playing) =>
            update((registry) => {
                Object.assign(mine(registry), { seen: now(), playing });
            }),
        heartbeat: () =>
            update((registry) => {
                mine(registry).seen = now();
            }),
        close: () =>
            update((registry) => {
                delete registry[id];
            }),
        isLeader() {
            try {
                const alive = Object.entries(read()).filter(
                    ([tabId, tab]) =>
                        tabId === id || now() - tab.seen <= TAB_TIMEOUT_MS,
                );
                const playing = alive.filter(([, tab]) => tab.playing);
                const candidates = playing.length > 0 ? playing : alive;
                const [first] = [...candidates].sort(
                    ([leftId, left], [rightId, right]) =>
                        right.focusedAt - left.focusedAt ||
                        leftId.localeCompare(rightId),
                );

                return (first?.[0] ?? id) === id;
            } catch {
                return true;
            }
        },
    };

    leader.touch();

    return leader;
}
