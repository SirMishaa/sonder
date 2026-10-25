import { describe, expect, it, vi } from 'vitest';
import { runAction } from './actions';
import type { ActionDeps } from './actions';
import type { Target } from './targets';

const VIDEO: Target = {
    kind: 'video',
    videoId: 'N9SZaOJEWXU',
    title: 'Muse - Sunburn',
    artists: 'Muse',
};

const MIX: Target = {
    kind: 'mix',
    listId: 'RDN9SZaOJEWXU',
    seedVideoId: 'N9SZaOJEWXU',
    title: 'Mix - Muse - Sunburn',
};

function deps() {
    return {
        send: vi.fn<ActionDeps['send']>(async () => ({
            ok: true as const,
            value: { id: 'cmd', traceparent: 't', sonderOpen: true },
        })),
        loadMix: vi.fn(async () => [
            {
                videoId: 'a0000000000',
                title: 'A',
                artists: 'X',
                durationSeconds: 100,
                thumbnailUrl: null,
            },
        ]),
        pause: vi.fn(),
        notify: vi.fn(),
    };
}

describe('runAction', () => {
    it('plays a video now and pauses YouTube', async () => {
        const d = deps();

        await runAction('play_now', VIDEO, d);

        expect(d.send).toHaveBeenCalledWith({
            action: 'play_now',
            kind: 'video',
            listId: null,
            title: 'Muse - Sunburn',
            items: [
                {
                    videoId: 'N9SZaOJEWXU',
                    title: 'Muse - Sunburn',
                    artists: 'Muse',
                    durationSeconds: 0,
                    thumbnailUrl:
                        'https://i.ytimg.com/vi/N9SZaOJEWXU/hqdefault.jpg',
                },
            ],
        });
        expect(d.pause).toHaveBeenCalledOnce();
        expect(d.notify).toHaveBeenCalledWith('Envoyé à Sonder');
    });

    it('sends the tracks of a mix without pausing YouTube', async () => {
        const d = deps();

        await runAction('enqueue', MIX, d);

        expect(d.loadMix).toHaveBeenCalledWith('RDN9SZaOJEWXU', 'N9SZaOJEWXU');
        expect(d.send).toHaveBeenCalledWith(
            expect.objectContaining({
                action: 'enqueue',
                kind: 'mix',
                listId: 'RDN9SZaOJEWXU',
            }),
        );
        expect(d.pause).not.toHaveBeenCalled();
    });

    it('asks to open Sonder when no tab will play what was queued', async () => {
        const d = deps();
        d.send.mockResolvedValue({
            ok: true,
            value: { id: 'cmd', traceparent: 't', sonderOpen: false },
        });

        await runAction('play_next', VIDEO, d);

        expect(d.notify).toHaveBeenCalledWith("Ouvre Sonder pour l'écouter");
    });

    it('says when the extension is not connected', async () => {
        const d = deps();
        d.send.mockResolvedValue({ ok: false, error: 'signed-out' } as never);

        await runAction('enqueue', VIDEO, d);

        expect(d.notify).toHaveBeenCalledWith("Connecte l'extension à Sonder");
    });

    it('does not send a mix with nothing playable', async () => {
        const d = deps();
        d.loadMix.mockResolvedValue([]);

        await runAction('play_now', MIX, d);

        expect(d.send).not.toHaveBeenCalled();
        expect(d.notify).toHaveBeenCalledWith('Rien à envoyer dans ce mix');
    });

    it('sends at most 50 tracks of a long list', async () => {
        const d = deps();
        d.loadMix.mockResolvedValue(
            Array.from({ length: 80 }, (_, index) => ({
                videoId: `v${String(index).padStart(10, '0')}`,
                title: `T${index}`,
                artists: 'X',
                durationSeconds: 1,
                thumbnailUrl: null,
            })),
        );

        await runAction('enqueue', MIX, d);

        expect(d.send.mock.calls[0]?.[0].items).toHaveLength(50);
    });

    it('names a video YouTube gave no title', async () => {
        const d = deps();

        await runAction('enqueue', { ...VIDEO, title: '', artists: '' }, d);

        expect(d.send.mock.calls[0]?.[0]).toMatchObject({
            title: 'Sans titre',
            items: [{ title: 'Sans titre' }],
        });
    });

    it('says when Sonder refused what was sent', async () => {
        const d = deps();
        d.send.mockResolvedValue({ ok: false, error: 'rejected' } as never);

        await runAction('enqueue', VIDEO, d);

        expect(d.notify).toHaveBeenCalledWith("Sonder a refusé l'envoi");
    });
});
