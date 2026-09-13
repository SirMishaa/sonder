type MercureMessage = {
    channels?: string[];
    event: string;
    payload: unknown;
};

/**
 * Subscribes to a Laravel private broadcasting channel over Mercure using
 * the native `EventSource` API. There is no `laravel-echo` dependency here:
 * its Mercure connector is not published yet (merged, but no npm release as
 * of this writing), so this reimplements the small slice of the protocol
 * this app needs — auth, one topic, JSON messages. No presence, whispers, or
 * end-to-end encryption, and no refresh of the auth cookie before it expires
 * (`subscribe_expiration` is 15 minutes server-side, which comfortably
 * covers a sync).
 *
 * Returns an unsubscribe function.
 */
export function subscribeToPrivateChannel(
    channel: string,
    onMessage: (message: MercureMessage) => void,
): () => void {
    let eventSource: EventSource | null = null;
    let cancelled = false;

    const channelName = `private-${channel}`;

    void fetch('/broadcasting/auth', {
        method: 'POST',
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ channel_names: [channelName] }),
    })
        .then((response) => {
            if (!response.ok || cancelled) {
                return null;
            }

            return response.json() as Promise<{ topic_prefix: string }>;
        })
        .then((data) => {
            if (!data || cancelled) {
                return;
            }

            const topic = `${data.topic_prefix}channel/${encodeURIComponent(channelName)}`;

            eventSource = new EventSource(`/.well-known/mercure?topic=${encodeURIComponent(topic)}`, {
                withCredentials: true,
            });

            eventSource.onmessage = (event: MessageEvent<string>) => {
                onMessage(JSON.parse(event.data) as MercureMessage);
            };
        });

    return () => {
        cancelled = true;
        eventSource?.close();
    };
}
