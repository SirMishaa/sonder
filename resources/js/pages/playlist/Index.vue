<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ListMusic, LoaderCircle, Settings2 } from 'lucide-vue-next';
import { onMounted, onUnmounted, reactive } from 'vue';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import YouTubeMusicConnectionController from '@/actions/App/Http/Controllers/YouTubeMusicConnectionController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { subscribeToPrivateChannel } from '@/lib/mercure';
import type { BreadcrumbItem } from '@/types';

type Props = {
    accountName: string;
    playlists: App.Data.PlaylistSummaryData[];
    activeSync: App.Data.YouTubeMusicSyncData | null;
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Playlists', href: PlaylistController.index() },
];

const sync = props.activeSync
    ? reactive({ ...props.activeSync, visible: true })
    : null;

let unsubscribe: (() => void) | null = null;

onMounted(() => {
    if (!sync) {
        return;
    }

    unsubscribe = subscribeToPrivateChannel(
        `youtube-music-sync.${sync.id}`,
        (message) => {
            if (message.event !== 'sync.updated') {
                return;
            }

            const payload = message.payload as App.Data.YouTubeMusicSyncData;
            Object.assign(sync, payload);

            if (payload.status === 'completed') {
                sync.visible = false;
                router.reload({ only: ['playlists', 'accountName'] });
            } else if (payload.status === 'failed') {
                sync.visible = false;
            }
        },
    );
});

onUnmounted(() => unsubscribe?.());
</script>

<template>
    <Head title="Playlists" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <div class="flex items-end justify-between gap-4">
                <Heading
                    :title="`${playlists.length} playlists`"
                    :description="`Signed in to YouTube Music as ${accountName}`"
                />
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
                    >
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
