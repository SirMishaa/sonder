<script setup lang="ts">
import { useEcho } from '@laravel/echo-vue';
import { useEventListener } from '@vueuse/core';
import { watch } from 'vue';
import { usePlayer } from '@/composables/usePlayer';
import { applyPlayerCommand } from '@/lib/player/playerCommands';
import type { PlayerCommandPayload } from '@/lib/player/playerCommands';
import { createTabLeader } from '@/lib/player/tabLeader';

/**
 * Replays the commands the browser extension sends. Every open Sonder tab
 * hears them; only the elected one (playing, else last focused) acts.
 */
const props = defineProps<{
    userId: string;
}>();

const player = usePlayer();
const leader = createTabLeader();

useEventListener(window, 'focus', () => leader.touch());
useEventListener(window, 'pagehide', () => leader.setPlaying(false));

watch(
    () => player.state.playing,
    (playing) => leader.setPlaying(playing),
    { immediate: true },
);

useEcho<PlayerCommandPayload>(
    `player.${props.userId}`,
    '.player.command',
    (command) => {
        if (leader.isLeader()) {
            applyPlayerCommand(player, command);
        }
    },
);
</script>

<template>
    <span hidden />
</template>
