<script setup lang="ts">
import QueueContents from '@/components/shell/QueueContents.vue';
import { usePlayer } from '@/composables/usePlayer';

const player = usePlayer();
</script>

<template>
    <aside
        class="material-glass queue min-h-0 overflow-hidden rounded-none border-y-0 border-r-0 transition-[width] duration-[240ms] ease-out-quint"
        :class="player.state.queueOpen ? 'w-[340px]' : 'w-0 border-l-0'"
        :aria-label="$t('Queue')"
        :aria-hidden="!player.state.queueOpen"
    >
        <QueueContents
            :active="player.state.queueOpen"
            class="h-full w-[340px] overflow-y-auto px-3 pt-4 pb-32"
        />
    </aside>
</template>
