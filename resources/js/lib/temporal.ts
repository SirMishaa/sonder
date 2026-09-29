/**
 * Makes the global Temporal API available.
 *
 * Browsers that ship Temporal (Chrome 144+, Firefox 139+) use it directly and
 * download nothing more. The others get the polyfill as a separate chunk,
 * fetched only by them, before the app mounts.
 */
export async function ensureTemporal(): Promise<void> {
    if (!('Temporal' in globalThis)) {
        await import('temporal-polyfill/global');
    }
}
