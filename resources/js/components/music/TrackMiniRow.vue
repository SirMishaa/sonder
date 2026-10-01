<script setup lang="ts">
import { GripVertical, X } from 'lucide-vue-next';
import PlayableArtwork from '@/components/music/PlayableArtwork.vue';
import type { QueueTrack } from '@/composables/usePlayer';

/**
 * One upcoming track. A sortable row (the queue panel) also gets a grip to
 * drag it, a remove button, and leaves keyboard focus to its list.
 */
type Props = {
    track: QueueTrack;
    number: number;
    sortable?: boolean;
    selected?: boolean;
    grabbed?: boolean;
};

withDefaults(defineProps<Props>(), {
    sortable: false,
    selected: false,
    grabbed: false,
});

defineEmits<{
    select: [event: MouseEvent];
    remove: [];
    grab: [event: PointerEvent];
}>();
</script>

<template>
    <div
        class="mini-row group grid grid-cols-[22px_minmax(0,1fr)_auto] items-center gap-3 rounded-lg px-2"
        :class="{ 'is-selected': selected, 'is-grabbed': grabbed }"
    >
        <span
            class="relative grid h-full place-items-center text-[12.5px] font-medium text-faint"
        >
            <span
                class="transition-opacity duration-150"
                :class="{ 'group-hover:opacity-0': sortable }"
                >{{ number }}</span
            >
            <span
                v-if="sortable"
                class="grip absolute inset-0 grid place-items-center opacity-0 transition-opacity duration-150 group-hover:opacity-100"
                aria-hidden="true"
                @pointerdown="$emit('grab', $event)"
            >
                <GripVertical class="size-4" />
            </span>
        </span>
        <button
            type="button"
            class="grid min-w-0 grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-1.5 text-left"
            :tabindex="sortable ? -1 : undefined"
            @click="$emit('select', $event)"
        >
            <PlayableArtwork :src="track.thumbnailUrl" class="size-9 rounded" />
            <span class="min-w-0">
                <span class="block truncate font-semibold">{{
                    track.title
                }}</span>
                <span class="block truncate text-[12.5px] text-dim">{{
                    track.artists
                }}</span>
            </span>
            <span class="text-[12.5px] font-medium text-faint">{{
                track.duration
            }}</span>
        </button>
        <button
            v-if="sortable"
            type="button"
            class="remove"
            tabindex="-1"
            :aria-label="
                $t('Remove :title from up next', { title: track.title })
            "
            @click="$emit('remove')"
        >
            <X class="size-3.5" />
        </button>
    </div>
</template>

<style scoped>
.mini-row {
    position: relative;
    transition:
        transform 220ms var(--ease-out-quint),
        opacity 220ms var(--ease-out-quint),
        background 140ms,
        box-shadow 200ms var(--ease-out-quint),
        scale 200ms var(--ease-out-quint);
}

.mini-row:hover {
    background: var(--color-hover);
}

.mini-row.is-selected {
    background: var(--color-raised);
    box-shadow: inset 0 0 0 1px oklch(0.8 0.15 68 / 0.45);
}

.grip {
    cursor: grab;
    touch-action: none;
    color: var(--color-dim);
}

.mini-row.is-grabbed {
    z-index: 2;
    background: var(--color-raised);
    box-shadow:
        0 18px 34px -16px oklch(0 0 0 / 0.9),
        inset 0 0 0 1px oklch(0.8 0.15 68 / 0.35);
    scale: 1.02;
    transition:
        background 140ms,
        box-shadow 200ms var(--ease-out-quint),
        scale 200ms var(--ease-out-quint);
}

.mini-row.is-grabbed .grip {
    cursor: grabbing;
    opacity: 1;
    color: var(--color-amber);
}

.remove {
    display: grid;
    place-items: center;
    width: 26px;
    height: 26px;
    border-radius: 7px;
    color: var(--color-faint);
    opacity: 0;
    transition:
        opacity 140ms,
        background 140ms,
        color 140ms;
}

.mini-row:hover .remove,
.mini-row.is-selected .remove {
    opacity: 1;
}

.remove:hover {
    background: oklch(0.65 0.18 28 / 0.16);
    color: oklch(0.78 0.14 28);
}
</style>
