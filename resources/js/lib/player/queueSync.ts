import { http } from '@inertiajs/vue3';
import PlayerQueueController from '@/actions/App/Http/Controllers/PlayerQueueController';

/** What the server keeps of the queue; device settings stay in the browser. */
export type QueueSnapshot = Omit<App.Data.PlayerQueueData, 'version'>;

export type QueueSync = {
    /**
     * Saves the snapshot; resolves to the new version, or null on failure.
     * A `traceparent` joins the save to the trace that caused it.
     */
    save(snapshot: QueueSnapshot, traceparent?: string): Promise<number | null>;
};

type SyncDeps = {
    url?: () => string;
    request?: (
        url: string,
        snapshot: QueueSnapshot,
        traceparent?: string,
    ) => Promise<{ status: number; data: string }>;
};

/**
 * Saves the queue through Inertia's HTTP client, which adds the
 * X-XSRF-TOKEN header itself.
 */
export function createQueueSync(deps: SyncDeps = {}): QueueSync {
    const url = deps.url ?? (() => PlayerQueueController.update().url);
    const request =
        deps.request ??
        ((target: string, snapshot: QueueSnapshot, traceparent?: string) =>
            http.getClient().request({
                method: 'put',
                url: target,
                data: snapshot,
                headers: {
                    Accept: 'application/json',
                    ...(traceparent ? { traceparent } : {}),
                },
            }));

    return {
        async save(snapshot, traceparent) {
            try {
                const response = await request(url(), snapshot, traceparent);

                if (response.status < 200 || response.status >= 300) {
                    return null;
                }

                const body = JSON.parse(response.data) as { version?: unknown };

                return typeof body.version === 'number' ? body.version : null;
            } catch {
                return null;
            }
        },
    };
}
