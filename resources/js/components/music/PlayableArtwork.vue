<script setup lang="ts">
import { Pause, Play } from 'lucide-vue-next';
import Artwork from '@/components/music/Artwork.vue';

/**
 * Artwork that reveals a play (or pause) icon when its row is hovered or
 * focused. The row itself is the button and must carry the `group` class;
 * this only shows that clicking it plays.
 */
type Props = {
    src: string | null;
    playing?: boolean;
};

withDefaults(defineProps<Props>(), { playing: false });
</script>

<template>
    <div class="relative overflow-hidden">
        <Artwork :src="src" class="size-full" />
        <span
            class="absolute inset-0 grid place-items-center bg-black/45 text-white opacity-0 transition-opacity duration-200 group-hover:opacity-100 group-focus-visible:opacity-100"
            aria-hidden="true"
        >
            <Pause v-if="playing" class="size-1/2 max-w-5 fill-current" />
            <Play v-else class="size-1/2 max-w-5 fill-current" />
        </span>
    </div>
</template>
