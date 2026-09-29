<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pause, Play, Plus, SkipForward, X } from 'lucide-vue-next';
import SonderMark from '@/components/brand/SonderMark.vue';
import SonderWordmark from '@/components/brand/SonderWordmark.vue';
import CoverArt from '@/components/landing/CoverArt.vue';
import Equalizer from '@/components/music/Equalizer.vue';
import Waveform from '@/components/music/Waveform.vue';

/** Mirrors DESIGN.md. Swatches use the values directly: `@theme inline` only emits variables for tokens the CSS actually uses. */
const colors: {
    group: string;
    tokens: { name: string; token: string; value: string }[];
}[] = [
    {
        group: 'Accent',
        tokens: [
            { name: 'Amp Amber', token: 'amber', value: 'oklch(0.80 0.15 68)' },
            {
                name: 'Amber Light',
                token: 'amber-light',
                value: 'oklch(0.84 0.14 75)',
            },
            {
                name: 'Burnt Amber',
                token: 'amber-deep',
                value: 'oklch(0.66 0.17 48)',
            },
            {
                name: 'Amber Ink',
                token: 'amber-ink',
                value: 'oklch(0.22 0.04 60)',
            },
        ],
    },
    {
        group: 'Surfaces',
        tokens: [
            {
                name: 'Ink Background',
                token: 'ink',
                value: 'oklch(0.165 0.012 60)',
            },
            { name: 'Sleeve', token: 'sleeve', value: 'oklch(0.182 0.013 60)' },
            { name: 'Panel', token: 'panel', value: 'oklch(0.19 0.013 60)' },
            { name: 'Hover', token: 'hover', value: 'oklch(0.215 0.014 60)' },
            { name: 'Raised', token: 'raised', value: 'oklch(0.235 0.014 60)' },
            {
                name: 'Groove Line',
                token: 'line',
                value: 'oklch(0.285 0.012 60)',
            },
        ],
    },
    {
        group: 'Text and status',
        tokens: [
            {
                name: 'Paper Text',
                token: 'paper',
                value: 'oklch(0.94 0.012 80)',
            },
            { name: 'Muted Text', token: 'dim', value: 'oklch(0.72 0.018 70)' },
            {
                name: 'Faint Text',
                token: 'faint',
                value: 'oklch(0.60 0.016 70)',
            },
            {
                name: 'Signal Green',
                token: 'signal',
                value: 'oklch(0.78 0.12 150)',
            },
            { name: 'Alarm Red', token: 'alarm', value: 'oklch(0.66 0.18 28)' },
        ],
    },
];

const typeScale = [
    {
        name: 'Display',
        spec: '800 · 44px · -0.04em',
        class: 'text-[44px] leading-none font-extrabold tracking-[-0.04em]',
    },
    {
        name: 'Headline',
        spec: '800 · 30px · -0.03em',
        class: 'text-[30px] leading-[1.1] font-extrabold tracking-[-0.03em]',
    },
    { name: 'Title', spec: '700 · 14px', class: 'text-[14px] font-bold' },
    { name: 'Body', spec: '400 · 14.5px · tabular', class: 'text-[14.5px]' },
    {
        name: 'Meta',
        spec: '500 · 12.5px · tabular',
        class: 'text-[12.5px] font-medium text-faint',
    },
    {
        name: 'Kicker',
        spec: '700 · 13px · amber',
        class: 'text-[13px] font-bold text-amber',
    },
];
</script>

<template>
    <Head :title="$t('Design system')" />

    <div class="mx-auto w-[min(1200px,100%-2*clamp(16px,4vw,40px))] pb-32">
        <header class="flex items-center justify-between py-5">
            <Link
                href="/"
                class="flex items-center gap-2"
                :aria-label="$t('Sonder, home')"
            >
                <SonderMark spinning class="size-9" />
                <SonderWordmark />
            </Link>
            <a
                href="https://github.com/SirMishaa/sonder/blob/main/DESIGN.md"
                class="text-[14px] font-semibold text-dim transition-colors hover:text-paper"
                >DESIGN.md</a
            >
        </header>

        <p class="reveal text-[13px] font-bold text-amber">
            The Listening Room
        </p>
        <h1
            class="reveal text-[30px] leading-[1.1] font-extrabold tracking-[-0.03em]"
            style="--i: 1"
        >
            {{ $t('Design system') }}
        </h1>
        <p class="reveal mt-1 max-w-[62ch] text-dim" style="--i: 2">
            {{
                $t(
                    'Every token and component of Sonder, rendered live from the code. DESIGN.md explains the rules behind them.',
                )
            }}
        </p>

        <section class="ds-section">
            <h2 class="ds-title">{{ $t('Colors') }}</h2>
            <div v-for="palette in colors" :key="palette.group" class="mt-5">
                <p class="mb-2.5 text-[12.5px] font-medium text-faint">
                    {{ palette.group }}
                </p>
                <div
                    class="grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-3"
                >
                    <div v-for="color in palette.tokens" :key="color.token">
                        <div
                            class="h-16 rounded-lg shadow-[inset_0_0_0_1px_oklch(1_0_0/0.08)]"
                            :style="{
                                background: color.value,
                            }"
                        />
                        <p class="mt-2 text-[13px] font-bold">
                            {{ color.name }}
                        </p>
                        <p class="text-[12px] font-medium text-faint">
                            --color-{{ color.token }}
                        </p>
                        <p class="text-[12px] text-faint">{{ color.value }}</p>
                    </div>
                </div>
            </div>
            <div
                class="mt-5 h-10 rounded-lg"
                style="background: var(--accent-grad)"
            />
            <p class="mt-2 text-[12.5px] font-medium text-faint">
                {{
                    $t(
                        'The logo gradient, used whenever amber fills a surface.',
                    )
                }}
            </p>
        </section>

        <section class="ds-section">
            <h2 class="ds-title">{{ $t('Typography') }}</h2>
            <p class="mt-1 text-[13px] text-dim">
                Hanken Grotesk · Bricolage Grotesque ({{ $t('wordmark only') }})
            </p>
            <div class="mt-5 divide-y divide-line/60">
                <div
                    v-for="style in typeScale"
                    :key="style.name"
                    class="grid grid-cols-[160px_minmax(0,1fr)] items-baseline gap-6 py-3.5"
                >
                    <div>
                        <p class="text-[13px] font-bold">{{ style.name }}</p>
                        <p class="text-[12px] font-medium text-faint">
                            {{ style.spec }}
                        </p>
                    </div>
                    <p :class="style.class" class="truncate">
                        Teardrop · Massive Attack · 5:29
                    </p>
                </div>
            </div>
        </section>

        <section class="ds-section">
            <h2 class="ds-title">{{ $t('Materials') }}</h2>
            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <div class="material-tile page-tile">
                    <p class="font-bold">Page</p>
                    <p class="text-[12.5px] text-faint">{{ $t('Grain') }} 5%</p>
                </div>
                <div class="material-tile material-sleeve">
                    <p class="font-bold">Sleeve</p>
                    <p class="text-[12.5px] text-faint">{{ $t('Grain') }} 9%</p>
                </div>
                <div class="material-tile material-glass">
                    <p class="font-bold">Glass</p>
                    <p class="text-[12.5px] text-faint">
                        {{ $t('Grain') }} 18–22%, blur
                    </p>
                </div>
            </div>
        </section>

        <section class="ds-section">
            <h2 class="ds-title">{{ $t('Buttons and controls') }}</h2>
            <div class="mt-5 flex flex-wrap items-center gap-3">
                <button type="button" class="ds-button accent-fill">
                    {{ $t('Primary') }}
                </button>
                <button type="button" class="ds-button ds-secondary">
                    {{ $t('Secondary') }}
                </button>
                <button type="button" class="ds-button accent-fill" disabled>
                    {{ $t('Disabled') }}
                </button>
                <button type="button" class="ds-button ds-secondary">
                    {{ $t('Refresh') }} <kbd class="keycap">R</kbd>
                </button>
                <button type="button" class="ds-icon" :aria-label="$t('Next')">
                    <SkipForward class="size-4 fill-current" />
                </button>
                <button type="button" class="ds-icon" :aria-label="$t('add')">
                    <Plus class="size-4" />
                </button>
                <button type="button" class="ds-icon" :aria-label="$t('skip')">
                    <X class="size-4" />
                </button>
                <button type="button" class="ds-play" :aria-label="$t('Play')">
                    <Play class="ml-0.5 size-[18px] fill-current" />
                </button>
                <button type="button" class="ds-play" :aria-label="$t('Pause')">
                    <Pause class="size-[18px] fill-current" />
                </button>
            </div>
            <div
                class="mt-5 flex flex-wrap items-center gap-2.5 text-[12.5px] font-medium"
            >
                <kbd class="keycap">⌘K</kbd><kbd class="keycap">J</kbd
                ><kbd class="keycap">K</kbd>
                <span class="rounded-full border border-line px-2 text-dim"
                    >trip hop</span
                >
                <span class="rounded-full border border-line px-2 text-dim"
                    >downtempo</span
                >
                <span class="text-faint"
                    >{{ $t('near') }}
                    <em class="font-semibold text-amber not-italic"
                        >Portishead</em
                    ></span
                >
            </div>
        </section>

        <section class="ds-section">
            <h2 class="ds-title">{{ $t('Music') }}</h2>
            <div class="mt-5 grid gap-6 sm:grid-cols-2">
                <div class="flex items-center gap-5">
                    <SonderMark spinning class="size-14" />
                    <SonderMark class="size-14" />
                    <SonderWordmark class="!text-[34px]" />
                </div>
                <div
                    class="flex items-center gap-5 text-[12.5px] font-medium text-faint"
                >
                    <span class="flex items-center gap-2"
                        ><Equalizer /> {{ $t('Playing') }}</span
                    >
                    <span class="flex items-center gap-2"
                        ><Equalizer :playing="false" /> {{ $t('Paused') }}</span
                    >
                    <span class="flex items-center gap-2"
                        ><span class="size-[7px] rounded-full bg-signal" />
                        {{ $t('Synced') }}</span
                    >
                    <span class="flex items-center gap-2"
                        ><span class="size-1.5 rounded-full bg-amber" />
                        {{ $t('Changed') }}</span
                    >
                </div>
                <div class="relative h-14 overflow-hidden rounded-lg bg-panel">
                    <Waveform
                        seed="design-system"
                        class="absolute inset-2"
                        :strength="0.3"
                    />
                </div>
                <div class="flex items-center gap-3">
                    <CoverArt
                        v-for="hue in [250, 18, 95, 350, 160]"
                        :key="hue"
                        :hue="hue"
                        class="size-12 overflow-hidden rounded-md"
                    />
                </div>
                <div class="h-1 overflow-hidden rounded-full bg-line">
                    <i
                        class="block h-full w-2/5"
                        style="
                            background: var(--accent-grad);
                            box-shadow: 0 0 10px oklch(0.8 0.15 68 / 0.45);
                        "
                    />
                </div>
                <div class="flex items-center gap-3">
                    <div class="skeleton size-12" />
                    <div class="flex-1 space-y-2">
                        <div class="skeleton h-3 w-2/3" />
                        <div class="skeleton h-3 w-1/3" />
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
.ds-section {
    margin-top: 56px;
    padding-top: 28px;
    border-top: 1px solid oklch(0.285 0.012 60 / 0.6);
}

.ds-title {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.03em;
}

.material-tile {
    display: flex;
    min-height: 120px;
    flex-direction: column;
    justify-content: flex-end;
    border-radius: 12px;
    padding: 14px;
}

.page-tile {
    background: var(--color-ink);
    box-shadow: inset 0 0 0 1px oklch(1 0 0 / 0.06);
}

.ds-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-radius: 9px;
    padding: 8px 14px;
    font-size: 14px;
    font-weight: 700;
    transition:
        filter 140ms,
        transform 140ms,
        border-color 140ms;
}

.ds-button:active:not(:disabled) {
    transform: scale(0.97);
}

.ds-button:disabled {
    opacity: 0.45;
}

.ds-secondary {
    border: 1px solid var(--color-line);
    background: oklch(0.19 0.013 60 / 0.8);
}

.ds-secondary:hover {
    border-color: oklch(0.4 0.014 60);
}

.ds-icon {
    display: grid;
    place-items: center;
    width: 32px;
    height: 32px;
    border-radius: 999px;
    color: var(--color-dim);
    transition:
        color 140ms,
        background 140ms;
}

.ds-icon:hover {
    color: var(--color-paper);
    background: var(--color-raised);
}

.ds-play {
    display: grid;
    place-items: center;
    width: 38px;
    height: 38px;
    border-radius: 999px;
    background: var(--accent-grad-d);
    color: var(--color-amber-ink);
    box-shadow: 0 6px 18px -6px oklch(0.7 0.17 55 / 0.7);
}
</style>
