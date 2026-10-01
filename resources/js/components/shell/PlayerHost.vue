<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { usePlayer } from '@/composables/usePlayer';
import type { PlayerTransport } from '@/lib/player/transport';
import { createYouTubeTransport } from '@/lib/player/youtubeTransport';

/**
 * Hosts the YouTube iframe: 1px, offscreen, transparent. Not display:none,
 * which stops the player from initialising.
 */
const props = withDefaults(
    defineProps<{
        createTransport?: (element: HTMLElement) => PlayerTransport;
        /** The queue saved on the server, handed over once per app load. */
        savedQueue?: App.Data.PlayerQueueData | null;
    }>(),
    { createTransport: createYouTubeTransport },
);

const player = usePlayer();
const mount = ref<HTMLDivElement | null>(null);
let transport: PlayerTransport | null = null;

onMounted(() => {
    window.addEventListener('pagehide', player.handlePageHide);
    player.hydrate(props.savedQueue);

    if (mount.value) {
        transport = props.createTransport(mount.value);
        player.attachTransport(transport);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('pagehide', player.handlePageHide);
    transport?.destroy();
});
</script>

<template>
    <div class="player-host" aria-hidden="true" inert>
        <div ref="mount" />
    </div>
</template>

<style scoped>
.player-host {
    position: fixed;
    top: 0;
    left: -10px;
    width: 1px;
    height: 1px;
    overflow: hidden;
    opacity: 0;
    pointer-events: none;
}
</style>
