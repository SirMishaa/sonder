import type { Api } from './api';
import { Rejected, SignedOut } from './api';
import type { Message, Result } from './messages';
import type { TokenStore } from './token';

type Deps = {
    token: TokenStore;
    api: Api;
    sonderIsOpen(): Promise<boolean>;
    openSonder(): Promise<void>;
};

/**
 * The background worker's answer to the content scripts and the popup.
 * Chromium needs `sendResponse` and a `true` return to answer asynchronously:
 * a returned promise is ignored without the webextension polyfill.
 */
export function createMessageListener(deps: Deps) {
    async function attempt<T>(run: () => Promise<T>): Promise<Result<T>> {
        try {
            return { ok: true, value: await run() };
        } catch (error) {
            return {
                ok: false,
                error:
                    error instanceof SignedOut
                        ? 'signed-out'
                        : error instanceof Rejected
                          ? 'rejected'
                          : 'failed',
            };
        }
    }

    async function handle(message: Message): Promise<unknown> {
        switch (message.type) {
            case 'token':
                await deps.token.set(message.token);

                return { ok: true };
            case 'status':
                return { signedIn: (await deps.token.get()) !== null };
            case 'lookup':
                return attempt(() => deps.api.lookup(message.body));
            case 'command':
                return attempt(async () => {
                    const sent = await deps.api.command(message.body);
                    const sonderOpen = await deps.sonderIsOpen();

                    if (!sonderOpen && message.body.action === 'play_now') {
                        await deps.openSonder();
                    }

                    return { ...sent, sonderOpen };
                });
        }
    }

    return (
        message: Message,
        _sender: unknown,
        sendResponse: (reply: unknown) => void,
    ): true => {
        void handle(message).then(sendResponse);

        return true;
    };
}
