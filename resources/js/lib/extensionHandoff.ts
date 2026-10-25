/**
 * Hands a freshly created token to the browser extension, whose content
 * script listens on every Sonder page. Resolves true once the extension
 * acknowledges it, false when no extension answers within `timeoutMs`.
 */
export function handOffToken(
    token: string,
    timeoutMs = 3000,
): Promise<boolean> {
    return new Promise((resolve) => {
        const timer = setTimeout(() => finish(false), timeoutMs);

        function onMessage(event: MessageEvent<{ type?: unknown }>): void {
            if (
                event.source === window &&
                event.data?.type === 'sonder:extension-connected'
            ) {
                finish(true);
            }
        }

        function finish(connected: boolean): void {
            clearTimeout(timer);
            window.removeEventListener('message', onMessage);
            resolve(connected);
        }

        window.addEventListener('message', onMessage);
        window.postMessage(
            { type: 'sonder:extension-token', token },
            window.location.origin,
        );
    });
}
