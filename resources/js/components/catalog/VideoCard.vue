<script setup lang="ts">
import { Play } from 'lucide-vue-next';
import { computed } from 'vue';
import Artwork from '@/components/music/Artwork.vue';
import Equalizer from '@/components/music/Equalizer.vue';
import { formatCompactNumber } from '@/lib/i18n';

/**
 * A clip or live as a 16:9 thumbnail with its title and view count. The
 * whole card plays it; the one playing shows the equalizer.
 */
type Props = {
    video: App.Data.CatalogVideoData;
    current?: boolean;
    playing?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    current: false,
    playing: false,
});

defineEmits<{
    play: [];
}>();

const views = computed(() =>
    props.video.views === null ? '' : formatCompactNumber(props.video.views),
);
</script>

<template>
    <button
        type="button"
        class="video group min-w-0 text-left"
        :class="{ 'is-current': current }"
        :disabled="video.videoId === null"
        @click="$emit('play')"
    >
        <span class="relative block">
            <Artwork
                :src="video.thumbnailUrl"
                :alt="video.title"
                class="thumb aspect-video w-full rounded-lg"
            />
            <span v-if="current" class="now" aria-hidden="true">
                <Equalizer :playing="playing" />
            </span>
            <span v-else class="badge" aria-hidden="true">
                <Play class="size-4 fill-current" />
            </span>
        </span>
        <span
            class="mt-2 block truncate font-semibold"
            :class="{ 'text-amber': current }"
            >{{ video.title }}</span
        >
        <span
            v-if="views"
            class="block truncate text-[12.5px] font-medium text-faint"
            >{{ $t(':count views', { count: views }) }}</span
        >
    </button>
</template>

<style scoped>
.thumb {
    box-shadow:
        0 18px 30px -20px oklch(0 0 0 / 0.8),
        inset 0 0 0 1px oklch(1 0 0 / 0.06);
    transition: box-shadow 240ms var(--ease-out-quint);
}

.video:hover:not(:disabled) .thumb,
.video:focus-visible .thumb,
.video.is-current .thumb {
    box-shadow:
        0 0 0 1px oklch(0.8 0.15 68 / 0.35),
        0 20px 34px -20px oklch(0 0 0 / 0.9);
}

.video:focus-visible {
    outline-offset: 4px;
    border-radius: 8px;
}

.video:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}

.badge,
.now {
    position: absolute;
    right: 8px;
    bottom: 8px;
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border-radius: 999px;
}

.badge {
    background: var(--accent-grad-d);
    color: var(--color-amber-ink);
    opacity: 0;
    transform: translateY(6px);
    transition:
        opacity 240ms var(--ease-out-quint),
        transform 240ms var(--ease-out-quint);
}

.video:hover:not(:disabled) .badge,
.video:focus-visible .badge {
    opacity: 1;
    transform: none;
}

.now {
    background: oklch(0.165 0.012 60 / 0.82);
    color: var(--color-amber);
}

@media (prefers-reduced-motion: reduce) {
    .badge {
        transform: none;
    }
}
</style>
