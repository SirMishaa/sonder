import { SONDER_URL } from '@/lib/config';
import { listenForToken } from '@/lib/handoff';
import { ask } from '@/lib/messages';

/**
 * Runs on every Sonder page, since Inertia reaches the connect page without
 * a full load, and hands the token it receives to the background worker.
 */
export default defineContentScript({
    matches: [`${SONDER_URL}/*`],
    main() {
        listenForToken(window, (token) => ask({ type: 'token', token }));
    },
});
