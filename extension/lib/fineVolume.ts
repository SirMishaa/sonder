export type GainGraph = { setGain(gain: number): void };

const START_TIMEOUT_MS = 1000;

/**
 * Routes the YouTube player's video through a Web Audio gain, so Sonder can
 * go below the player's 1 % floor. Nothing is connected unless the audio
 * context actually runs: a suspended context would silence the video.
 */
export async function attachGain(doc: Document): Promise<GainGraph | null> {
    const video = doc.querySelector<HTMLVideoElement>(
        'video.html5-main-video, video',
    );

    if (!video) {
        return null;
    }

    const context = new AudioContext();

    await Promise.race([
        context.resume(),
        new Promise((resolve) => setTimeout(resolve, START_TIMEOUT_MS)),
    ]);

    if (context.state !== 'running') {
        await context.close();

        return null;
    }

    const gain = context.createGain();
    context.createMediaElementSource(video).connect(gain);
    gain.connect(context.destination);

    return {
        setGain: (value) =>
            gain.gain.setTargetAtTime(value, context.currentTime, 0.02),
    };
}

/**
 * Answers Sonder, the page embedding this YouTube player: says whether the
 * precise volume works and applies the gains it sends. Only messages from
 * Sonder's origin count. The graph is built once it succeeds; a failed
 * attempt is retried at the next hello.
 */
export function serveFineVolume(deps: {
    win: Window;
    parentOrigin: string;
    attach: () => Promise<GainGraph | null>;
    reply: (message: unknown) => void;
}): () => void {
    let graph: Promise<GainGraph | null> | null = null;

    function ensure(): Promise<GainGraph | null> {
        graph ??= deps.attach().then((attached) => {
            if (attached === null) {
                graph = null;
            }

            return attached;
        });

        return graph;
    }

    async function onMessage(event: MessageEvent): Promise<void> {
        const data = event.data as { type?: unknown; gain?: unknown } | null;

        if (event.origin !== deps.parentOrigin) {
            return;
        }

        if (data?.type === 'sonder:fine-volume-hello') {
            deps.reply({
                type: 'sonder:fine-volume',
                available: (await ensure()) !== null,
            });
        } else if (
            data?.type === 'sonder:fine-volume-gain' &&
            typeof data.gain === 'number'
        ) {
            (await ensure())?.setGain(Math.max(0, Math.min(1, data.gain)));
        }
    }

    const listener = (event: MessageEvent) => void onMessage(event);
    deps.win.addEventListener('message', listener);

    return () => deps.win.removeEventListener('message', listener);
}
