<script setup lang="ts">
import { ref } from 'vue';
import Artwork from '@/components/music/Artwork.vue';
import TrackMiniRow from '@/components/music/TrackMiniRow.vue';
import PlayerDebugPanel from '@/components/shell/PlayerDebugPanel.vue';
import { usePlayer } from '@/composables/usePlayer';

const player = usePlayer();

const VISIBLE_UP_NEXT = 40;

const debugOpen = ref(false);
</script>

<template>
    <aside
        class="material-glass queue min-h-0 overflow-hidden rounded-none border-y-0 border-r-0 transition-[width] duration-[240ms] ease-out-quint"
        :class="player.state.queueOpen ? 'w-[340px]' : 'w-0 border-l-0'"
        :aria-label="$t('Queue')"
        :aria-hidden="!player.state.queueOpen"
    >
        <div class="h-full w-[340px] overflow-y-auto px-3 pt-4 pb-32">
            <h2 class="px-2 pb-2.5 text-[13.5px] font-bold">
                {{ $t('Now playing') }}
            </h2>
            <div
                v-if="player.current.value"
                class="mb-4 grid grid-cols-[64px_1fr] gap-3 rounded-[10px] bg-raised p-2"
            >
                <Artwork
                    :src="player.current.value.thumbnailUrl"
                    class="size-16 rounded-md"
                />
                <div class="min-w-0 self-center">
                    <p class="truncate font-bold">
                        {{ player.current.value.title }}
                    </p>
                    <p class="truncate text-[13px] text-dim">
                        {{ player.current.value.artists }}
                    </p>
                </div>
            </div>
            <p v-else class="mb-4 px-2 text-sm text-faint">
                {{ $t('Nothing is playing.') }}
            </p>

            <h2
                class="flex items-baseline gap-2 px-2 pb-2.5 text-[13.5px] font-bold"
            >
                {{ $t('Up next') }}
                <span
                    v-if="player.state.source"
                    class="truncate text-[12.5px] font-medium text-faint"
                >
                    {{
                        $t('from :source', {
                            source: player.state.source.title,
                        })
                    }}
                </span>
            </h2>
            <TrackMiniRow
                v-for="(track, offset) in player.upNext.value.slice(
                    0,
                    VISIBLE_UP_NEXT,
                )"
                :key="track.key"
                :track="track"
                :number="offset + 1"
                @select="player.jumpTo(player.state.index + 1 + offset)"
            />
            <p
                v-if="player.current.value && player.upNext.value.length === 0"
                class="px-2 text-sm text-faint"
            >
                {{ $t('This is the last track.') }}
            </p>
            <button
                type="button"
                class="mt-6 px-2 text-xs text-faint transition-colors hover:text-dim"
                :aria-expanded="debugOpen"
                @click="debugOpen = !debugOpen"
            >
                {{ $t('Debug') }}
            </button>
            <PlayerDebugPanel v-if="debugOpen" class="mt-2" />
        </div>
    </aside>
</template>
