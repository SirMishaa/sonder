<script setup lang="ts">
import { Cloud, CloudAlert, CloudCheck, CloudOff } from 'lucide-vue-next';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';
import Artwork from '@/components/music/Artwork.vue';
import LiveNumber from '@/components/shell/LiveNumber.vue';
import { useRelativeTime } from '@/composables/useRelativeTime';

/**
 * The cloud in the sidebar's Playlists header: how far the library's
 * metadata got, from matching each track to a recording to fetching its
 * genres and credits.
 */
type Props = {
    summary: App.Data.LibraryEnrichmentData;
};

const props = defineProps<Props>();

const updatedAgo = useRelativeTime(() => props.summary.updatedAt);

const icon = computed(
    () =>
        ({
            idle: Cloud,
            running: Cloud,
            paused: CloudOff,
            done: CloudCheck,
            attention: CloudAlert,
        })[props.summary.state],
);

const settled = computed(
    () =>
        props.summary.resolved + props.summary.notFound + props.summary.failed,
);

/**
 * Each layer of the bar covers everything before it too, so a layer scales
 * from the left edge instead of moving: transforms only.
 */
const layers = computed(() => {
    const total = Math.max(1, props.summary.total);
    const { resolved, notFound, failed } = props.summary;

    return {
        resolved: resolved / total,
        notFound: (resolved + notFound) / total,
        failed: (resolved + notFound + failed) / total,
    };
});

/** Tracks that just joined the feed glow for a moment. */
const fresh = ref(new Set<string>());

watch(
    () => props.summary.recent.map((item) => item.videoId),
    (ids, previous) => {
        if (!previous) {
            return;
        }

        const arrived = ids.filter((id) => !previous.includes(id));

        if (arrived.length === 0) {
            return;
        }

        fresh.value = new Set(arrived);
        setTimeout(() => (fresh.value = new Set()), 1400);
    },
);

const legend = computed(() => [
    { key: 'resolved', label: 'Matched', count: props.summary.resolved },
    { key: 'not-found', label: 'Not found', count: props.summary.notFound },
    { key: 'failed', label: 'Failed', count: props.summary.failed },
    { key: 'pending', label: 'Waiting', count: props.summary.pending },
]);
</script>

<template>
    <PopoverRoot>
        <PopoverTrigger
            class="trigger"
            :data-state-enrichment="summary.state"
            :aria-label="$t('Metadata enrichment')"
        >
            <span class="relative grid place-items-center">
                <component :is="icon" class="size-[15px]" />
                <span
                    v-if="summary.state === 'running'"
                    class="particles"
                    aria-hidden="true"
                >
                    <i /><i /><i />
                </span>
            </span>
        </PopoverTrigger>

        <PopoverPortal>
            <PopoverContent
                side="right"
                align="start"
                :side-offset="14"
                :collision-padding="12"
                class="material-glass panel z-50 w-[316px] rounded-[14px] p-4 focus:outline-none"
            >
                <header class="flex items-center gap-2">
                    <h2 class="text-[14px] font-bold text-paper">
                        {{ $t('Metadata') }}
                    </h2>
                    <span class="chip" :data-state="summary.state">
                        <i class="chip-dot" aria-hidden="true" />
                        {{
                            {
                                idle: $t('Not started'),
                                running: $t('Live'),
                                paused: $t('Paused'),
                                done: $t('Up to date'),
                                attention: $t('Needs a retry'),
                            }[summary.state]
                        }}
                    </span>
                </header>

                <p class="mt-1.5 text-[12.5px] leading-snug text-dim">
                    <template v-if="summary.state === 'running'">{{
                        $t(
                            'Matching your tracks, then fetching their genres and credits.',
                        )
                    }}</template>
                    <template v-else-if="summary.state === 'paused'">{{
                        $t(
                            'The enrichment queue is paused. Nothing is lost, it picks up where it stopped.',
                        )
                    }}</template>
                    <template v-else-if="summary.state === 'done'">{{
                        $t('Every track has been looked up.')
                    }}</template>
                    <template v-else-if="summary.state === 'attention'">{{
                        $tChoice(
                            ':count track could not be looked up and will be retried tomorrow.|:count tracks could not be looked up and will be retried tomorrow.',
                            summary.failed,
                        )
                    }}</template>
                    <template v-else>{{
                        $t(
                            'Nothing looked up yet. Enrichment starts after the next sync.',
                        )
                    }}</template>
                </p>

                <div
                    class="bar mt-4"
                    :class="{ 'is-running': summary.state === 'running' }"
                    role="progressbar"
                    :aria-valuemin="0"
                    :aria-valuemax="summary.total"
                    :aria-valuenow="settled"
                    :aria-label="$t('Tracks looked up')"
                >
                    <span
                        class="layer layer-failed"
                        :style="{ transform: `scaleX(${layers.failed})` }"
                    />
                    <span
                        class="layer layer-not-found"
                        :style="{ transform: `scaleX(${layers.notFound})` }"
                    />
                    <span
                        class="layer layer-resolved"
                        :style="{ transform: `scaleX(${layers.resolved})` }"
                    />
                </div>

                <ul
                    class="mt-2.5 grid grid-cols-2 gap-x-4 gap-y-1 text-[12px] text-dim"
                >
                    <li
                        v-for="item in legend"
                        :key="item.key"
                        class="flex items-center gap-1.5"
                    >
                        <i class="swatch" :data-swatch="item.key" />
                        <span>{{ $t(item.label) }}</span>
                        <LiveNumber
                            :value="item.count"
                            class="ml-auto font-semibold text-paper"
                        />
                    </li>
                </ul>

                <dl class="stages mt-4">
                    <div class="stage">
                        <dt>{{ $t('Looked up') }}</dt>
                        <dd>
                            <LiveNumber :value="settled" class="text-paper" />
                            <span class="text-faint">
                                / <LiveNumber :value="summary.total" />
                            </span>
                        </dd>
                    </div>
                    <div class="stage">
                        <dt>{{ $t('Described') }}</dt>
                        <dd>
                            <LiveNumber
                                :value="summary.described"
                                class="text-paper"
                            />
                            <span class="text-faint">
                                / <LiveNumber :value="summary.recordings" />
                            </span>
                        </dd>
                    </div>
                    <div class="stage">
                        <dt>{{ $t('With a genre') }}</dt>
                        <dd>
                            <LiveNumber
                                :value="summary.withGenre"
                                class="text-paper"
                            />
                        </dd>
                    </div>
                </dl>

                <section v-if="summary.recent.length" class="feed mt-4">
                    <h3 class="text-[12px] font-bold text-faint">
                        {{ $t('Just enriched') }}
                    </h3>
                    <TransitionGroup
                        tag="ul"
                        name="feed"
                        class="relative mt-2 grid grid-cols-[minmax(0,1fr)] gap-1"
                    >
                        <li
                            v-for="item in summary.recent"
                            :key="item.videoId"
                            class="feed-item"
                            :class="{ 'is-fresh': fresh.has(item.videoId) }"
                        >
                            <Artwork
                                :src="item.thumbnailUrl"
                                class="size-7 shrink-0 rounded"
                            />
                            <div class="min-w-0 flex-1">
                                <p
                                    class="truncate text-[12.5px] leading-tight font-semibold text-paper"
                                >
                                    {{ item.title }}
                                </p>
                                <p
                                    class="mt-0.5 flex items-center gap-1 truncate text-[11.5px] leading-tight text-faint"
                                >
                                    <span class="truncate">{{
                                        item.artists
                                    }}</span>
                                    <template v-if="item.year">
                                        <i class="dot" />{{ item.year }}
                                    </template>
                                    <template v-if="item.creditCount">
                                        <i class="dot" />{{
                                            $tChoice(
                                                ':count credit|:count credits',
                                                item.creditCount,
                                            )
                                        }}
                                    </template>
                                </p>
                                <p
                                    v-if="item.genres.length"
                                    class="mt-1 flex gap-1 overflow-hidden"
                                >
                                    <span
                                        v-for="genre in item.genres"
                                        :key="genre"
                                        class="genre"
                                        >{{ genre }}</span
                                    >
                                </p>
                            </div>
                        </li>
                    </TransitionGroup>
                </section>

                <p class="mt-3 text-[11.5px] text-faint">
                    {{ $t('Updated :ago', { ago: updatedAgo }) }}
                </p>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>

<style scoped>
.trigger {
    display: grid;
    place-items: center;
    width: 24px;
    height: 24px;
    border-radius: 6px;
    color: var(--color-faint);
    transition:
        background 140ms,
        color 140ms;
}

.trigger:hover,
.trigger[data-state='open'] {
    background: var(--color-raised);
    color: var(--color-paper);
}

.trigger[data-state-enrichment='running'] {
    color: var(--color-amber);
}

/* Three motes rising off the cloud while metadata is being fetched. */
.particles i {
    position: absolute;
    left: 50%;
    top: 1px;
    width: 2.5px;
    height: 2.5px;
    border-radius: 999px;
    background: var(--color-amber-light);
    opacity: 0;
    animation: rise 1.8s var(--ease-out-quint) infinite;
}

.particles i:nth-child(1) {
    margin-left: -4px;
}

.particles i:nth-child(2) {
    animation-delay: 0.6s;
}

.particles i:nth-child(3) {
    margin-left: 3px;
    animation-delay: 1.2s;
}

@keyframes rise {
    0% {
        opacity: 0;
        transform: translateY(2px) scale(0.6);
    }

    25% {
        opacity: 1;
    }

    100% {
        opacity: 0;
        transform: translateY(-8px) scale(1);
    }
}

/*
 * Dense reading over busy artwork: the glass stays nearly opaque, its blur
 * only softens what shows through the edges.
 */
.panel {
    transform-origin: var(--reka-popover-content-transform-origin);
    background: linear-gradient(
        180deg,
        oklch(0.215 0.014 60 / 0.94),
        oklch(0.175 0.013 60 / 0.97)
    );
    backdrop-filter: blur(18px) saturate(1.3);
    -webkit-backdrop-filter: blur(18px) saturate(1.3);
}

.panel[data-state='open'] {
    animation: panel-in 240ms var(--ease-out-quint);
}

.panel[data-state='closed'] {
    animation: panel-out 140ms ease-in forwards;
}

@keyframes panel-in {
    from {
        opacity: 0;
        transform: translateX(-6px) scale(0.97);
        filter: blur(3px);
    }
}

@keyframes panel-out {
    to {
        opacity: 0;
        transform: translateX(-4px) scale(0.98);
    }
}

.chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-left: auto;
    padding: 1px 8px;
    border: 1px solid var(--color-line);
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--color-dim);
}

.chip-dot {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: var(--color-faint);
}

.chip[data-state='running'] {
    border-color: oklch(0.8 0.15 68 / 0.35);
    color: var(--color-amber-light);
}

.chip[data-state='running'] .chip-dot {
    background: var(--color-amber);
    animation: pulse 1.6s ease-out infinite;
}

.chip[data-state='done'] .chip-dot {
    background: var(--color-signal);
}

.chip[data-state='attention'] .chip-dot {
    background: var(--color-alarm);
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 oklch(0.8 0.15 68 / 0.55);
    }

    100% {
        box-shadow: 0 0 0 6px oklch(0.8 0.15 68 / 0);
    }
}

.bar {
    position: relative;
    height: 8px;
    overflow: hidden;
    border-radius: 999px;
    background: oklch(0.13 0.01 60 / 0.7);
    box-shadow: inset 0 0 0 1px oklch(1 0 0 / 0.05);
}

/* What is still waiting shimmers while the queue works through it. */
.bar.is-running::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
        90deg,
        transparent 0%,
        oklch(0.8 0.15 68 / 0.16) 50%,
        transparent 100%
    );
    background-size: 40% 100%;
    background-repeat: no-repeat;
    animation: sweep 2.2s var(--ease-out-quint) infinite;
}

@keyframes sweep {
    from {
        background-position: -40% 0;
    }

    to {
        background-position: 140% 0;
    }
}

.layer {
    position: absolute;
    inset: 0;
    transform-origin: left;
    transition: transform 520ms var(--ease-out-quint);
}

.layer-resolved {
    background: linear-gradient(
        90deg,
        var(--color-amber-deep),
        var(--color-amber-light)
    );
    box-shadow: 0 0 10px oklch(0.8 0.15 68 / 0.45);
}

.layer-not-found {
    background: oklch(0.42 0.02 70);
}

.layer-failed {
    background: oklch(0.55 0.15 28);
}

.swatch {
    width: 7px;
    height: 7px;
    flex-shrink: 0;
    border-radius: 2px;
}

.swatch[data-swatch='resolved'] {
    background: var(--color-amber);
}

.swatch[data-swatch='not-found'] {
    background: oklch(0.42 0.02 70);
}

.swatch[data-swatch='failed'] {
    background: oklch(0.55 0.15 28);
}

.swatch[data-swatch='pending'] {
    background: oklch(0.13 0.01 60);
    box-shadow: inset 0 0 0 1px var(--color-line);
}

.stages {
    display: grid;
    gap: 2px;
    padding-top: 12px;
    border-top: 1px solid oklch(0.285 0.012 60 / 0.6);
}

.stage {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    font-size: 13px;
    padding: 3px 0;
}

.stage dt {
    color: var(--color-dim);
}

.stage dd {
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

.feed {
    padding-top: 12px;
    border-top: 1px solid oklch(0.285 0.012 60 / 0.6);
}

.feed-item {
    display: flex;
    min-width: 0;
    align-items: flex-start;
    gap: 10px;
    padding: 6px 8px;
    margin: 0 -8px;
    border-radius: 8px;
    transition: background 900ms var(--ease-out-quint);
}

.feed-item.is-fresh {
    background: oklch(0.8 0.15 68 / 0.1);
    box-shadow: inset 0 0 0 1px oklch(0.8 0.15 68 / 0.28);
    transition-duration: 120ms;
}

.genre {
    padding: 0 7px;
    border: 1px solid var(--color-line);
    border-radius: 999px;
    font-size: 10.5px;
    line-height: 16px;
    white-space: nowrap;
    color: var(--color-dim);
}

.dot {
    width: 2.5px;
    height: 2.5px;
    flex-shrink: 0;
    border-radius: 999px;
    background: currentColor;
    opacity: 0.6;
}

/* A new track slides in from the top; the oldest fades off the bottom. */
.feed-move,
.feed-enter-active {
    transition:
        transform 320ms var(--ease-out-quint),
        opacity 320ms var(--ease-out-quint);
}

.feed-leave-active {
    position: absolute;
    left: 0;
    right: 0;
    transition:
        transform 220ms ease-in,
        opacity 220ms ease-in;
}

.feed-enter-from {
    opacity: 0;
    transform: translateY(-10px);
}

.feed-leave-to {
    opacity: 0;
    transform: translateY(8px);
}

@media (prefers-reduced-motion: reduce) {
    .particles,
    .bar.is-running::before {
        display: none;
    }

    .chip[data-state='running'] .chip-dot {
        animation: none;
    }

    .panel[data-state='open'],
    .panel[data-state='closed'] {
        animation-duration: 1ms;
    }

    .layer,
    .feed-move,
    .feed-enter-active,
    .feed-leave-active {
        transition: none;
    }
}
</style>
