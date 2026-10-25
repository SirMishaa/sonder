import { describe, expect, it } from 'vite-plus/test';
import { createTabLeader } from '@/lib/player/tabLeader';

function sharedStorage() {
    const data = new Map<string, string>();

    return {
        getItem: (key: string) => data.get(key) ?? null,
        setItem: (key: string, value: string) => {
            data.set(key, value);
        },
        removeItem: (key: string) => {
            data.delete(key);
        },
    };
}

describe('tabLeader', () => {
    it('elects one tab', () => {
        const storage = sharedStorage();
        let clock = 1_000;
        const now = () => clock;
        const first = createTabLeader({ storage, now, id: 'first' });
        clock += 10;
        const second = createTabLeader({ storage, now, id: 'second' });

        expect([first.isLeader(), second.isLeader()]).toEqual([false, true]);

        clock += 10;
        first.touch();

        expect([first.isLeader(), second.isLeader()]).toEqual([true, false]);
    });

    it('lets the playing tab lead whatever was focused last', () => {
        const storage = sharedStorage();
        const first = createTabLeader({ storage, id: 'first' });
        const second = createTabLeader({ storage, id: 'second' });

        first.setPlaying(true);
        second.touch();

        expect([first.isLeader(), second.isLeader()]).toEqual([true, false]);

        first.setPlaying(false);

        expect([first.isLeader(), second.isLeader()]).toEqual([false, true]);
    });

    it('leads alone when the shared storage is unavailable', () => {
        const leader = createTabLeader({
            storage: {
                getItem: () => {
                    throw new Error('denied');
                },
                setItem: () => {
                    throw new Error('denied');
                },
                removeItem: () => undefined,
            },
            id: 'alone',
        });

        expect(leader.isLeader()).toBe(true);
    });
});
