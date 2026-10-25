import type { LookupBody, LookupResult } from './api';

/** What the content scripts and the popup ask the background worker. */
export type Message =
    | { type: 'command'; body: App.Data.PlayerCommandData }
    | { type: 'lookup'; body: LookupBody }
    | { type: 'token'; token: string }
    | { type: 'status' };

export type Reply<M extends Message> = M extends { type: 'command' }
    ? Result<{ id: string; traceparent: string; sonderOpen: boolean }>
    : M extends { type: 'lookup' }
      ? Result<LookupResult>
      : M extends { type: 'status' }
        ? { signedIn: boolean }
        : { ok: true };

export type Result<T> =
    | { ok: true; value: T }
    | { ok: false; error: 'signed-out' | 'failed' };

/** Sends a message to the background worker and waits for its reply. */
export function ask<M extends Message>(message: M): Promise<Reply<M>> {
    return browser.runtime.sendMessage(message) as Promise<Reply<M>>;
}
