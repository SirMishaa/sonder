function hex(bytes: Uint8Array): string {
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join(
        '',
    );
}

/**
 * A W3C trace context header for a new trace, so every call an action makes
 * to Sonder lands in one trace on the server.
 */
export function newTraceparent(
    random: (length: number) => Uint8Array = (length) =>
        crypto.getRandomValues(new Uint8Array(length)),
): string {
    return `00-${hex(random(16))}-${hex(random(8))}-01`;
}
