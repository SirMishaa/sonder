/**
 * Picks up the token the "Connect the extension" page posts to its own
 * window, stores it, then tells the page it arrived. Only messages from
 * Sonder's own origin count.
 */
export function listenForToken(
    win: Window,
    store: (token: string) => Promise<unknown>,
): void {
    win.addEventListener('message', (event: MessageEvent) => {
        const data = event.data as { type?: unknown; token?: unknown } | null;

        if (
            event.origin !== win.location.origin ||
            data?.type !== 'sonder:extension-token' ||
            typeof data.token !== 'string'
        ) {
            return;
        }

        void store(data.token).then(() =>
            win.postMessage(
                { type: 'sonder:extension-connected' },
                win.location.origin,
            ),
        );
    });
}
