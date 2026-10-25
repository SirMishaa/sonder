import { initializeFaro } from '@grafana/faro-web-sdk';

/** The part of Faro the extension uses. */
export type FaroLike = {
    api: { pushEvent(name: string, attributes?: Record<string, string>): void };
};

type FaroEnv = { url?: string; app: string; version: string };

let faro: FaroLike | null = null;

/**
 * Starts Faro when a collector URL is configured (WXT_FARO_URL); without one
 * it stays off and sends nothing. Grafana is moving to a self-hosted
 * instance, so the URL is left empty for now.
 */
export function initFaro(
    env: FaroEnv,
    create: (env: Required<FaroEnv>) => FaroLike = ({ url, app, version }) =>
        initializeFaro({ url, app: { name: app, version } }),
): FaroLike | null {
    faro = env.url ? create({ ...env, url: env.url }) : null;

    return faro;
}

export function stopFaro(): void {
    faro = null;
}

/**
 * Reports something that went wrong quietly (YouTube markup that no longer
 * matches, a failed send). A no-op while Faro is off.
 */
export function reportIssue(
    name: string,
    attributes: Record<string, string> = {},
): void {
    faro?.api.pushEvent('sonder.issue', { name, ...attributes });
}

/** Records an action sent to Sonder, with the trace it started on the server. */
export function trackAction(event: {
    action: string;
    kind: string;
    items: number;
    traceparent: string;
}): void {
    faro?.api.pushEvent('sonder.action', {
        action: event.action,
        kind: event.kind,
        items: String(event.items),
        traceparent: event.traceparent,
    });
}
