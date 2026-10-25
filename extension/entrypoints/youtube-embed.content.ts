import { SONDER_URL } from '@/lib/config';
import { attachGain, serveFineVolume } from '@/lib/fineVolume';

/**
 * Runs inside the YouTube player Sonder embeds, to bring its volume below
 * the player's 1 % floor with a Web Audio gain.
 */
export default defineContentScript({
    matches: ['*://www.youtube.com/embed/*'],
    allFrames: true,
    main() {
        if (window.top === window) {
            return;
        }

        const sonder = new URL(SONDER_URL).origin;

        serveFineVolume({
            win: window,
            doc: document,
            parentOrigin: sonder,
            attach: () => attachGain(document),
            reply: (message) => window.parent.postMessage(message, sonder),
        });
    },
});
