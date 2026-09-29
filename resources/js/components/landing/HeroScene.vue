<script setup lang="ts">
import { useIntervalFn } from '@vueuse/core';
import {
    ListMusic,
    Pause,
    Search,
    SkipBack,
    SkipForward,
    Volume2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import SonderMark from '@/components/brand/SonderMark.vue';
import SonderWordmark from '@/components/brand/SonderWordmark.vue';
import CoverArt from '@/components/landing/CoverArt.vue';
import Equalizer from '@/components/music/Equalizer.vue';
import Waveform from '@/components/music/Waveform.vue';
import { formatSeconds } from '@/composables/usePlayer';
import { formatAgo } from '@/lib/i18n';
import { playingIndex, playingSeconds, playlists, tracks } from '@/lib/landing';

const playing = tracks[playingIndex];
const elapsed = ref(96);

useIntervalFn(() => {
    elapsed.value = (elapsed.value + 1) % playingSeconds;
}, 1000);

const progress = computed(() => elapsed.value / playingSeconds);
</script>

<template>
    <div
        class="scene"
        role="img"
        :aria-label="
            $t('Sonder playing :title by :artist from a playlist', {
                title: playing.title,
                artist: playing.artists,
            })
        "
    >
        <div class="glow" aria-hidden="true" />

        <div class="frame" aria-hidden="true">
            <aside class="material-sleeve sleeve">
                <div class="flex items-center gap-2 px-1">
                    <SonderMark spinning class="size-8" />
                    <SonderWordmark class="!text-[21px]" />
                </div>
                <div
                    class="mt-4 flex items-center gap-2 rounded-lg bg-ink px-2.5 py-1.5 text-[12.5px] text-faint"
                >
                    <Search class="size-3.5" />
                    {{ $t('Search') }}
                    <span class="keycap ml-auto">⌘K</span>
                </div>
                <p class="mt-5 mb-1.5 px-1 text-[12.5px] font-bold text-faint">
                    {{ $t('Playlists') }}
                </p>
                <ul class="space-y-0.5">
                    <li
                        v-for="playlist in playlists"
                        :key="playlist.title"
                        class="relative flex items-center gap-2.5 overflow-hidden rounded-lg px-1.5 py-1"
                        :class="{ 'bg-raised': playlist.playing }"
                    >
                        <Waveform
                            v-if="playlist.playing"
                            seed="late-focus"
                            :bars="28"
                            class="absolute inset-y-1 right-8 left-10"
                        />
                        <CoverArt
                            :hue="playlist.hue"
                            class="relative size-7 shrink-0 overflow-hidden rounded"
                        />
                        <span
                            class="relative min-w-0 flex-1 truncate text-[13px] font-semibold"
                            :class="
                                playlist.playing ? 'text-paper' : 'text-dim'
                            "
                            >{{ playlist.title }}</span
                        >
                        <Equalizer v-if="playlist.playing" class="relative" />
                        <span
                            v-else-if="playlist.changed"
                            class="size-1.5 rounded-full bg-amber"
                        />
                    </li>
                </ul>
            </aside>

            <section class="main">
                <header class="flex items-end gap-4">
                    <CoverArt
                        :hue="250"
                        class="size-[72px] shrink-0 overflow-hidden rounded-lg shadow-[0_18px_30px_-20px_oklch(0_0_0/0.8)]"
                    />
                    <div class="min-w-0">
                        <p class="text-[13px] font-bold text-amber">
                            {{ $t('Playlist') }}
                        </p>
                        <p
                            class="truncate text-[30px] leading-none font-extrabold tracking-[-0.04em]"
                        >
                            Late focus
                        </p>
                        <p class="mt-1.5 text-[12.5px] font-medium text-faint">
                            {{ $tChoice(':count track|:count tracks', 48) }} ·
                            {{
                                $t('Checked :ago', {
                                    ago: formatAgo(4, 'minute'),
                                })
                            }}
                        </p>
                    </div>
                </header>

                <ol class="mt-4">
                    <li
                        v-for="(track, index) in tracks"
                        :key="track.title"
                        class="relative grid grid-cols-[20px_28px_minmax(0,1fr)_auto] items-center gap-3 overflow-hidden rounded-md px-2 py-1.5"
                        :class="{ 'is-playing': index === playingIndex }"
                    >
                        <Waveform
                            v-if="index === playingIndex"
                            seed="roads"
                            :bars="44"
                            :strength="0.12"
                            class="absolute inset-y-1 right-16 left-1/2"
                        />
                        <span
                            class="relative text-right text-[12.5px] font-medium text-faint"
                        >
                            <Equalizer v-if="index === playingIndex" />
                            <template v-else>{{ index + 1 }}</template>
                        </span>
                        <CoverArt
                            :hue="track.hue"
                            class="relative size-7 overflow-hidden rounded"
                        />
                        <span class="relative min-w-0">
                            <span
                                class="block truncate text-[13.5px] font-semibold"
                                :class="{
                                    'text-amber': index === playingIndex,
                                }"
                                >{{ track.title }}</span
                            >
                            <span class="block truncate text-[12.5px] text-dim"
                                >{{ track.artists }} · {{ track.album }}</span
                            >
                        </span>
                        <span
                            class="relative text-[12.5px] font-medium text-faint"
                            >{{ track.duration }}</span
                        >
                    </li>
                </ol>
            </section>

            <div class="material-glass player">
                <CoverArt
                    :hue="playing.hue"
                    class="size-10 shrink-0 overflow-hidden rounded-md"
                />
                <div class="min-w-0">
                    <p class="truncate text-[13.5px] font-bold">
                        {{ playing.title }}
                    </p>
                    <p class="truncate text-[12.5px] text-dim">
                        {{ playing.artists }}
                    </p>
                </div>
                <div class="flex flex-col items-center gap-1.5">
                    <div class="flex items-center gap-3 text-dim">
                        <SkipBack class="size-4 fill-current" />
                        <span class="play"
                            ><Pause class="size-4 fill-current"
                        /></span>
                        <SkipForward class="size-4 fill-current" />
                    </div>
                    <div
                        class="grid w-full grid-cols-[32px_1fr_32px] items-center gap-2 text-[11px] font-medium text-faint"
                    >
                        <span>{{ formatSeconds(elapsed) }}</span>
                        <span class="h-1 overflow-hidden rounded-full bg-line">
                            <i
                                class="bar block h-full origin-left"
                                :style="{ transform: `scaleX(${progress})` }"
                            />
                        </span>
                        <span class="text-right">{{ playing.duration }}</span>
                    </div>
                </div>
                <div
                    class="hidden items-center justify-end gap-2 text-dim sm:flex"
                >
                    <Volume2 class="size-4" />
                    <span class="volume"><i /></span>
                    <ListMusic class="size-4" />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.scene {
    position: relative;
    isolation: isolate;
}

/* The amp light: the scene sits in the warm pool the logo casts. */
.glow {
    position: absolute;
    inset: -24% -14% -16% -40%;
    z-index: -1;
    background:
        radial-gradient(
            50% 45% at 30% 30%,
            oklch(0.8 0.15 68 / 0.36),
            transparent 70%
        ),
        radial-gradient(
            45% 40% at 75% 80%,
            oklch(0.66 0.17 48 / 0.26),
            transparent 70%
        );
    filter: blur(24px);
}

.frame {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.3fr);
    gap: 0;
    overflow: hidden;
    border-radius: 18px;
    background: var(--color-ink);
    box-shadow:
        0 40px 80px -40px oklch(0 0 0 / 0.9),
        0 0 0 1px oklch(1 0 0 / 0.07);
    padding-bottom: 76px;
}

.sleeve {
    padding: 14px 10px;
    border-right: 1px solid oklch(0.285 0.012 60 / 0.6);
}

.sleeve::after {
    content: '';
    position: absolute;
    inset: -40px auto auto -40px;
    width: 220px;
    height: 180px;
    z-index: -1;
    background: radial-gradient(
        closest-side,
        oklch(0.8 0.15 68 / 0.16),
        transparent
    );
}

.main {
    padding: 18px 14px 8px;
    background: radial-gradient(
        120% 70% at 20% 0%,
        oklch(0.32 0.06 250 / 0.35),
        transparent 70%
    );
}

.is-playing {
    background: oklch(0.8 0.15 68 / 0.06);
}

.player {
    position: absolute;
    right: 12px;
    bottom: 12px;
    left: 12px;
    display: grid;
    grid-template-columns: auto minmax(0, 0.9fr) minmax(0, 1.4fr) auto;
    align-items: center;
    gap: 12px;
    padding: 9px 12px;
    border-radius: 14px;
}

.play {
    display: grid;
    place-items: center;
    width: 32px;
    height: 32px;
    border-radius: 999px;
    background: var(--accent-grad-d);
    color: var(--color-amber-ink);
    box-shadow: 0 6px 18px -6px oklch(0.7 0.17 55 / 0.7);
}

.bar {
    background: var(--accent-grad);
    box-shadow: 0 0 10px oklch(0.8 0.15 68 / 0.45);
    transition: transform 1s linear;
}

.volume {
    display: block;
    width: 56px;
    height: 4px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--color-line);
}

.volume i {
    display: block;
    width: 70%;
    height: 100%;
    background: var(--accent-grad);
}

@media (max-width: 639px) {
    .frame {
        grid-template-columns: minmax(0, 1fr);
    }

    .sleeve {
        display: none;
    }

    .player {
        grid-template-columns: auto minmax(0, 1fr) minmax(0, 1.2fr);
    }
}
</style>
