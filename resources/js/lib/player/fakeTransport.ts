import { createEmitter } from '@/lib/player/transport';
import type {
    LoadOptions,
    PlaybackState,
    PlayerTransport,
} from '@/lib/player/transport';

/**
 * In-memory transport for tests. Commands behave like a cooperative
 * player (play emits "playing", load with autoplay emits "playing");
 * the driver methods simulate what YouTube would report.
 */
export class FakeTransport implements PlayerTransport {
    loads: Array<{ videoId: string } & LoadOptions> = [];
    calls: string[] = [];
    position = 0;
    length = 180;
    currentVideo: string | null = null;
    volume = 100;
    muted = false;

    private readonly emitter = createEmitter();
    private cuedStart: number | null = null;

    on: PlayerTransport['on'] = (event, listener) =>
        this.emitter.on(event, listener);

    load(videoId: string, options: LoadOptions): void {
        this.loads.push({ videoId, ...options });
        this.calls.push(`load:${videoId}`);
        this.currentVideo = videoId;
        // Worst case of YouTube: a cued video reports 0 until it plays.
        this.position = options.autoplay ? options.startAt : 0;
        this.cuedStart = options.autoplay ? null : options.startAt;
        this.emitter.emit('state', options.autoplay ? 'playing' : 'cued');
    }

    play(): void {
        this.calls.push('play');

        if (this.cuedStart !== null) {
            this.position = this.cuedStart;
            this.cuedStart = null;
        }

        this.emitter.emit('state', 'playing');
    }

    pause(): void {
        this.calls.push('pause');
        this.emitter.emit('state', 'paused');
    }

    seek(seconds: number): void {
        this.calls.push(`seek:${seconds}`);
        this.position = seconds;
    }

    setVolume(volume: number): void {
        this.volume = volume;
    }

    setMuted(muted: boolean): void {
        this.muted = muted;
    }

    currentTime(): number {
        return this.position;
    }

    duration(): number {
        return this.currentVideo === null ? 0 : this.length;
    }

    videoId(): string | null {
        return this.currentVideo;
    }

    destroy(): void {
        this.calls.push('destroy');
        this.emitter.clear();
    }

    becomeReady(): void {
        this.emitter.emit('ready');
    }

    becomeUnavailable(): void {
        this.emitter.emit('unavailable');
    }

    emitState(state: PlaybackState): void {
        this.emitter.emit('state', state);
    }

    finish(): void {
        this.position = this.length;
        this.emitter.emit('state', 'ended');
    }

    fail(code: number): void {
        this.emitter.emit('error', code);
    }

    blockAutoplay(): void {
        this.emitter.emit('autoplayBlocked');
    }
}
