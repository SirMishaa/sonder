<script setup lang="ts">
import { useEcho } from '@laravel/echo-vue';

type Props = {
    syncId: string;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    update: [sync: App.Data.YouTubeMusicSyncData];
}>();

useEcho<App.Data.YouTubeMusicSyncData>(
    `youtube-music-sync.${props.syncId}`,
    '.sync.updated',
    (payload) => {
        emit('update', payload);
    },
);
</script>

<template>
    <span hidden />
</template>
