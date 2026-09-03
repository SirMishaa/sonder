<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { ListMusic, Music2 } from 'lucide-vue-next';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    playlistId: string;
    summary: App.Data.PlaylistSummaryData | null;
    playlist?: App.Data.PlaylistData;
};

const props = defineProps<Props>();

const title = props.summary?.title ?? 'Playlist';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Playlists', href: PlaylistController.index() },
    { title, href: PlaylistController.show(props.playlistId) },
];

const retry = () => router.reload({ only: ['playlist'] });
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-8 p-4">
            <header class="flex flex-col gap-5 sm:flex-row sm:items-end">
                <div
                    class="aspect-square w-40 shrink-0 overflow-hidden rounded-xl border bg-muted"
                >
                    <img
                        v-if="summary?.thumbnailUrl"
                        :src="summary.thumbnailUrl"
                        :alt="title"
                        class="size-full object-cover"
                    />
                    <div
                        v-else
                        class="flex size-full items-center justify-center"
                    >
                        <ListMusic class="size-10 text-muted-foreground" />
                    </div>
                </div>

                <div class="min-w-0 flex-1 space-y-1">
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Playlist
                    </p>
                    <h1 class="truncate text-3xl font-semibold">{{ title }}</h1>
                    <p
                        v-if="summary?.description"
                        class="line-clamp-2 text-sm text-muted-foreground"
                    >
                        {{ summary.description }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="playlist?.trackCount">
                            {{ playlist.trackCount }} tracks
                            <template v-if="playlist.duration">
                                · {{ playlist.duration }}
                            </template>
                        </template>
                        <template v-else-if="summary?.author">
                            {{ summary.author }}
                        </template>
                    </p>
                </div>
            </header>

            <Deferred data="playlist">
                <template #fallback>
                    <div class="space-y-1">
                        <div
                            v-for="row in 12"
                            :key="row"
                            class="flex animate-pulse items-center gap-3 rounded-lg px-3 py-2"
                        >
                            <Skeleton class="size-10 shrink-0 rounded" />
                            <div class="min-w-0 flex-1 space-y-1.5">
                                <Skeleton class="h-3.5 w-1/3" />
                                <Skeleton class="h-3 w-1/5" />
                            </div>
                            <Skeleton class="h-3 w-10 shrink-0" />
                        </div>
                    </div>
                </template>

                <template #rescue="{ reloading }">
                    <div
                        class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center"
                    >
                        <Music2 class="size-8 text-muted-foreground" />
                        <div>
                            <p class="font-medium">Could not load the tracks</p>
                            <p class="text-sm text-muted-foreground">
                                YouTube Music refused the request, or the stored
                                cookie has expired.
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="reloading"
                            @click="retry"
                        >
                            {{ reloading ? 'Retrying…' : 'Retry' }}
                        </Button>
                    </div>
                </template>

                <div
                    v-if="playlist && playlist.tracks.length > 0"
                    class="space-y-0.5"
                >
                    <div
                        v-for="(track, position) in playlist.tracks"
                        :key="`${track.videoId ?? 'unavailable'}-${position}`"
                        class="group flex items-center gap-3 rounded-lg px-3 py-2 transition-colors hover:bg-muted/60"
                        :class="{ 'opacity-40': !track.isAvailable }"
                    >
                        <span
                            class="w-6 shrink-0 text-right font-mono text-xs text-muted-foreground tabular-nums"
                        >
                            {{ position + 1 }}
                        </span>

                        <div
                            class="size-10 shrink-0 overflow-hidden rounded border bg-muted"
                        >
                            <img
                                v-if="track.thumbnailUrl"
                                :src="track.thumbnailUrl"
                                :alt="track.title"
                                loading="lazy"
                                class="size-full object-cover"
                            />
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ track.title }}
                                <span
                                    v-if="track.isExplicit"
                                    class="ml-1 rounded bg-muted px-1 text-[10px] font-semibold text-muted-foreground"
                                >
                                    E
                                </span>
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ track.artists }}
                            </p>
                        </div>

                        <p
                            class="hidden min-w-0 flex-1 truncate text-xs text-muted-foreground md:block"
                        >
                            {{ track.album }}
                        </p>

                        <span
                            class="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                        >
                            {{ track.duration }}
                        </span>
                    </div>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-2 rounded-xl border border-dashed p-12 text-center"
                >
                    <Music2 class="size-8 text-muted-foreground" />
                    <p class="font-medium">This playlist is empty</p>
                </div>
            </Deferred>
        </div>
    </AppLayout>
</template>
