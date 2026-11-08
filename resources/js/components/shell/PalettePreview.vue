<script setup lang="ts">
import type { Component } from 'vue';
import Artwork from '@/components/music/Artwork.vue';

/**
 * The palette's side pane for the selected item: its artwork, title and
 * subtitle, then the tracks it holds (the top artist's tracks among the
 * suggestions) or a hint of what Enter does.
 */
type Props = {
    label: string;
    sub?: string;
    artwork?: string | null;
    icon?: Component;
    round?: boolean;
    tracks?: App.Data.CatalogTrackData[];
    hint?: string;
};

defineProps<Props>();
</script>

<template>
    <aside
        class="max-h-[440px] overflow-y-auto border-l border-line p-[18px]"
        aria-hidden="true"
    >
        <Artwork
            v-if="artwork !== undefined"
            :src="artwork"
            class="mx-auto aspect-square w-full max-w-[132px] shadow-[0_24px_40px_-22px_oklch(0_0_0/0.95)]"
            :class="round ? 'rounded-full' : 'rounded-lg'"
        />
        <div
            v-else-if="icon"
            class="mx-auto grid aspect-square w-full max-w-[132px] place-items-center rounded-lg bg-raised"
        >
            <component :is="icon" class="size-8 text-faint" />
        </div>
        <h3
            class="mt-3.5 truncate text-center text-xl font-extrabold tracking-[-0.02em]"
        >
            {{ label }}
        </h3>
        <p
            v-if="sub"
            class="truncate text-center text-[12.5px] font-medium text-faint"
        >
            {{ sub }}
        </p>
        <template v-if="tracks?.length">
            <p class="pt-3.5 pb-1.5 text-xs font-bold text-faint">
                {{ $t('Tracks') }}
            </p>
            <div
                v-for="(track, index) in tracks"
                :key="`${track.videoId}-${index}`"
                class="grid grid-cols-[18px_minmax(0,1fr)_auto] gap-2 py-1 text-[13px]"
            >
                <span class="text-right text-faint">{{ index + 1 }}</span>
                <span class="truncate">{{ track.title }}</span>
                <span class="text-faint">{{ track.duration }}</span>
            </div>
        </template>
        <p v-else-if="hint" class="mt-3.5 text-center text-[12.5px] text-faint">
            {{ hint }}
        </p>
    </aside>
</template>
