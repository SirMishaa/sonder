<script setup lang="ts">
import Artwork from '@/components/music/Artwork.vue';
import type { QueueTrack } from '@/composables/usePlayer';

type Props = {
    track: QueueTrack;
    number: number;
};

defineProps<Props>();

defineEmits<{
    select: [];
}>();
</script>

<template>
    <button
        type="button"
        class="grid w-full grid-cols-[22px_36px_minmax(0,1fr)_auto] items-center gap-3 rounded-lg px-2 py-1.5 text-left transition-colors hover:bg-hover"
        @click="$emit('select')"
    >
        <span class="text-right text-[12.5px] font-medium text-faint">{{
            number
        }}</span>
        <Artwork :src="track.thumbnailUrl" class="size-9 rounded" />
        <span class="min-w-0">
            <span class="block truncate font-semibold">{{ track.title }}</span>
            <span class="block truncate text-[12.5px] text-dim">{{
                track.artists
            }}</span>
        </span>
        <span class="text-[12.5px] font-medium text-faint">{{
            track.duration
        }}</span>
    </button>
</template>
