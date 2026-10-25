import { SONDER_URL } from '@/lib/config';
import { ask } from '@/lib/messages';

/**
 * Picks up the token the "Connect the extension" page posts to its own
 * window, and hands it to the background worker.
 */
export default defineContentScript({
    matches: [`${SONDER_URL}/settings/extension*`],
    main() {
        window.addEventListener('message', (event: MessageEvent) => {
            const data = event.data as { type?: unknown; token?: unknown };

            if (
                event.source === window &&
                event.origin === window.location.origin &&
                data?.type === 'sonder:extension-token' &&
                typeof data.token === 'string'
            ) {
                void ask({ type: 'token', token: data.token });
            }
        });
    },
});
