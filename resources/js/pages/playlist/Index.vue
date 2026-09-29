<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import Artwork from '@/components/music/Artwork.vue';
import Equalizer from '@/components/music/Equalizer.vue';
import { usePlayer } from '@/composables/usePlayer';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const page = usePage();
const player = usePlayer();

const playlists = computed(() => page.props.library?.playlists ?? []);
const removedCount = computed(
    () => playlists.value.filter((playlist) => playlist.isRemoved).length,
);
</script>

<template>
    <Head title="Library" />

    <div class="max-w-[1480px] px-8 pt-8">
        <h1
            class="reveal text-[30px] leading-[1.1] font-extrabold tracking-[-0.03em]"
        >
            Library
        </h1>
        <p class="reveal mt-1 mb-7 text-dim" style="--i: 1">
            {{ playlists.length }} playlists, synced from YouTube Music as
            {{ page.props.library?.accountName }}.
            <template v-if="removedCount">
                <span class="text-alarm"
                    >{{ removedCount }} no longer in your library.</span
                >
            </template>
        </p>

        <div
            class="grid grid-cols-[repeat(auto-fill,minmax(168px,1fr))] gap-x-4 gap-y-7"
        >
            <Link
                v-for="(playlist, index) in playlists"
                :key="playlist.id"
                :href="PlaylistController.show(playlist.id)"
                class="card reveal min-w-0"
                :style="{ '--i': Math.min(index, 16) + 2 }"
            >
                <div class="relative">
                    <Artwork
                        :src="playlist.thumbnailUrl"
                        :alt="playlist.title"
                        class="aspect-square rounded-md"
                        :class="playlist.isRemoved ? 'removed' : 'shadow-card'"
                    />
                    <span
                        v-if="playlist.isRemoved"
                        class="absolute top-2 left-2 rounded-full bg-alarm px-2 py-0.5 text-[11px] font-semibold text-[oklch(0.98_0.01_80)]"
                    >
                        Removed from YouTube Music
                    </span>
                    <span
                        v-if="player.isPlayingFrom(playlist.id)"
                        class="absolute right-2 bottom-2 grid size-8 place-items-center rounded-full bg-[oklch(0.17_0.013_60/0.8)] backdrop-blur"
                    >
                        <Equalizer :playing="player.state.playing" />
                    </span>
                </div>
                <p
                    class="mt-2.5 truncate text-sm font-semibold"
                    :class="{ 'text-amber': player.isPlayingFrom(playlist.id) }"
                >
                    {{ playlist.title }}
                </p>
                <p class="text-[12.5px] font-medium text-faint">
                    {{
                        playlist.trackCount
                            ? `${playlist.trackCount} tracks`
                            : 'Auto playlist'
                    }}
                </p>
            </Link>
        </div>

        <p
            v-if="playlists.length === 0"
            class="rounded-xl border border-dashed border-line p-12 text-center text-dim"
        >
            No playlists yet. The first sync fills this page.
        </p>
    </div>
</template>

<style scoped>
.shadow-card {
    box-shadow:
        0 18px 30px -20px oklch(0 0 0 / 0.8),
        inset 0 0 0 1px oklch(1 0 0 / 0.06);
}

.removed {
    box-shadow:
        0 0 0 1px var(--color-alarm),
        0 0 0 4px oklch(0.66 0.18 28 / 0.3);
}

.card :deep(img) {
    transition:
        opacity 420ms var(--ease-out-quint),
        transform 420ms var(--ease-out-quint);
}

.card:hover :deep(img) {
    transform: scale(1.045);
}
</style>
