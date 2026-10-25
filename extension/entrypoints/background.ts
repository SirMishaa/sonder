import { createApi, SignedOut } from '@/lib/api';
import { SONDER_URL } from '@/lib/config';
import type { Message, Result } from '@/lib/messages';
import { tokenStore } from '@/lib/token';

export default defineBackground(() => {
    const token = tokenStore();
    const api = createApi({ baseUrl: SONDER_URL, token });

    async function attempt<T>(run: () => Promise<T>): Promise<Result<T>> {
        try {
            return { ok: true, value: await run() };
        } catch (error) {
            return {
                ok: false,
                error: error instanceof SignedOut ? 'signed-out' : 'failed',
            };
        }
    }

    async function sonderIsOpen(): Promise<boolean> {
        const tabs = await browser.tabs.query({ url: `${SONDER_URL}/*` });

        return tabs.length > 0;
    }

    async function handle(message: Message): Promise<unknown> {
        switch (message.type) {
            case 'token':
                await token.set(message.token);

                return { ok: true };
            case 'status':
                return { signedIn: (await token.get()) !== null };
            case 'lookup':
                return attempt(() => api.lookup(message.body));
            case 'command':
                return attempt(async () => {
                    const sent = await api.command(message.body);
                    const sonderOpen = await sonderIsOpen();

                    if (!sonderOpen && message.body.action === 'play_now') {
                        await browser.tabs.create({
                            url: `${SONDER_URL}/dashboard`,
                        });
                    }

                    return { ...sent, sonderOpen };
                });
        }
    }

    browser.runtime.onMessage.addListener((message: Message) =>
        handle(message),
    );
});
