<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { ListMusic, LoaderCircle, Settings2 } from 'lucide-vue-next';
import { computed, reactive } from 'vue';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import YouTubeMusicConnectionController from '@/actions/App/Http/Controllers/YouTubeMusicConnectionController';
import Heading from '@/components/Heading.vue';
import SyncedAgo from '@/components/SyncedAgo.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    accountName: string;
    playlists: App.Data.PlaylistSummaryData[];
    removedPlaylistIds: string[];
    lastCheckedAt: string | null;
    activeSync: App.Data.YouTubeMusicSyncData | null;
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Playlists', href: PlaylistController.index() },
];

const removedPlaylistIds = computed(() => new Set(props.removedPlaylistIds));

const sync = props.activeSync
    ? reactive({ ...props.activeSync, visible: true })
    : null;

if (sync) {
    useEcho<App.Data.YouTubeMusicSyncData>(
        `youtube-music-sync.${sync.id}`,
        '.sync.updated',
        (payload) => {
            Object.assign(sync, payload);

            if (payload.status === 'completed') {
                sync.visible = false;
                router.reload({
                    only: [
                        'playlists',
                        'removedPlaylistIds',
                        'lastCheckedAt',
                        'accountName',
                    ],
                });
            } else if (payload.status === 'failed') {
                sync.visible = false;
            }
        },
    );
}
</script>

<template>
    <Head title="Playlists" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <div class="flex items-end justify-between gap-4">
                <div class="space-y-1">
                    <Heading
                        :title="`${playlists.length} playlists`"
                        :description="`Signed in to YouTube Music as ${accountName}`"
                    />
                    <SyncedAgo v-if="lastCheckedAt" :at="lastCheckedAt" />
                </div>
                <div
                    v-if="sync?.visible"
                    class="flex items-center gap-2 rounded-full border bg-muted/50 px-3 py-1.5 text-xs text-muted-foreground"
                >
                    <LoaderCircle class="size-3.5 animate-spin" />
                    Syncing… {{ sync.syncedPlaylists }}/{{
                        sync.totalPlaylists ?? '…'
                    }}
                </div>
                <Button as-child variant="outline" size="sm">
                    <Link :href="YouTubeMusicConnectionController.create()">
                        <Settings2 />
                        Connection
                    </Link>
                </Button>
            </div>

            <div
                v-if="playlists.length === 0"
                class="flex flex-col items-center gap-2 rounded-xl border border-dashed p-12 text-center"
            >
                <ListMusic class="size-8 text-muted-foreground" />
                <p class="font-medium">No playlists found</p>
                <p class="max-w-sm text-sm text-muted-foreground">
                    Either this account has no playlists, or YouTube Music
                    refused the request. Try reconnecting.
                </p>
            </div>

            <div
                v-else
                class="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"
            >
                <Link
                    v-for="playlist in playlists"
                    :key="playlist.id"
                    :href="PlaylistController.show(playlist.id)"
                    class="group flex flex-col gap-2 rounded-xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    <div
                        class="relative aspect-square overflow-hidden rounded-lg border bg-muted"
                        :class="{
                            'border-destructive ring-2 ring-destructive/40':
                                removedPlaylistIds.has(playlist.id),
                        }"
                    >
                        <Badge
                            v-if="removedPlaylistIds.has(playlist.id)"
                            variant="destructive"
                            class="absolute top-2 left-2 z-10"
                        >
                            Removed from YouTube Music
                        </Badge>
                        <img
                            v-if="playlist.thumbnailUrl"
                            :src="playlist.thumbnailUrl"
                            :alt="playlist.title"
                            loading="lazy"
                            class="size-full object-cover transition duration-300 group-hover:scale-105"
                        />
                        <div
                            v-else
                            class="flex size-full items-center justify-center"
                        >
                            <ListMusic class="size-8 text-muted-foreground" />
                        </div>
                    </div>

                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">
                            {{ playlist.title }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            <template v-if="playlist.trackCount">
                                {{ playlist.trackCount }} tracks
                            </template>
                            <template v-else-if="playlist.author">
                                {{ playlist.author }}
                            </template>
                            <template v-else>Playlist</template>
                        </p>
                    </div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
