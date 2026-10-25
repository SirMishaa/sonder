import type { Result } from '../messages';
import type { MixItem } from './mix';
import type { Target } from './targets';

export type Action = App.Enums.PlayerCommandAction;

/** Sonder takes at most this many tracks per command (IssuePlayerCommandRequest). */
export const MAX_ITEMS = 50;

const UNTITLED = 'Sans titre';

export type ActionDeps = {
    send(
        body: App.Data.PlayerCommandData,
    ): Promise<
        Result<{ id: string; traceparent: string; sonderOpen: boolean }>
    >;
    loadMix(listId: string, seedVideoId: string | null): Promise<MixItem[]>;
    pause(): void;
    notify(message: string): void;
};

function itemsOf(target: Target, deps: ActionDeps): Promise<MixItem[]> {
    if (target.kind === 'mix') {
        return deps.loadMix(target.listId, target.seedVideoId);
    }

    return Promise.resolve([
        {
            videoId: target.videoId,
            title: target.title,
            artists: target.artists,
            durationSeconds: 0,
            thumbnailUrl: `https://i.ytimg.com/vi/${target.videoId}/hqdefault.jpg`,
        },
    ]);
}

/**
 * Sends a video or a mix to Sonder and tells the owner how it went. Playing
 * now also pauses YouTube, so the two never play at once.
 */
export async function runAction(
    action: Action,
    target: Target,
    deps: ActionDeps,
): Promise<void> {
    const items = await itemsOf(target, deps).catch(() => null);

    if (items === null) {
        deps.notify('YouTube ne donne pas ce mix');

        return;
    }

    const sent = items.slice(0, MAX_ITEMS).map((item) => ({
        ...item,
        title: item.title || UNTITLED,
    }));

    if (sent.length === 0) {
        deps.notify('Rien à envoyer dans ce mix');

        return;
    }

    const result = await deps.send({
        action,
        kind: target.kind,
        listId: target.kind === 'mix' ? target.listId : null,
        title: target.title || UNTITLED,
        items: sent,
    });

    if (!result.ok) {
        deps.notify(
            {
                'signed-out': "Connecte l'extension à Sonder",
                rejected: "Sonder a refusé l'envoi",
                failed: "Sonder n'a pas répondu",
            }[result.error],
        );

        return;
    }

    if (action === 'play_now') {
        deps.pause();
    }

    deps.notify(
        result.value.sonderOpen || action === 'play_now'
            ? 'Envoyé à Sonder'
            : "Ouvre Sonder pour l'écouter",
    );
}
