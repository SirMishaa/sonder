<script setup lang="ts">
import CoverArt from '@/components/landing/CoverArt.vue';
import { formatAgo } from '@/lib/i18n';
import { playlists } from '@/lib/landing';

/** How fresh each showcase playlist reads; null marks the removed one. */
const freshness: ([number, Intl.RelativeTimeFormatUnit] | null)[] = [
    [4, 'minute'],
    [3, 'day'],
    [2, 'week'],
    [4, 'minute'],
    null,
];

function ago(index: number): string | null {
    const entry = freshness[index];

    return entry ? formatAgo(...entry) : null;
}
</script>

<template>
    <div class="fragment rounded-xl border border-line" aria-hidden="true">
        <header
            class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3"
        >
            <p class="text-[13px] text-dim">
                {{
                    $tChoice(
                        ':count playlist, synced from YouTube Music as :account.|:count playlists, synced from YouTube Music as :account.',
                        17,
                        { account: 'Alex' },
                    )
                }}
            </p>
            <span
                class="flex items-center gap-1.5 text-xs font-medium text-faint"
            >
                <span class="size-[7px] rounded-full bg-signal" />
                {{ $t('Synced :ago', { ago: formatAgo(2, 'minute') }) }}
            </span>
        </header>

        <ul class="grid gap-1 p-2 sm:grid-cols-2">
            <li
                v-for="(playlist, index) in playlists"
                :key="playlist.title"
                class="flex items-center gap-3 rounded-lg px-2 py-2"
                :class="{ 'is-removed': index === 4 }"
            >
                <CoverArt
                    :hue="playlist.hue"
                    class="size-12 shrink-0 overflow-hidden rounded-md"
                />
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 truncate font-bold">
                        {{ playlist.title }}
                        <span
                            v-if="playlist.changed"
                            class="size-1.5 shrink-0 rounded-full bg-amber"
                        />
                    </p>
                    <p class="truncate text-[12.5px] font-medium text-faint">
                        <template v-if="ago(index)">
                            {{
                                $tChoice(
                                    ':count track|:count tracks',
                                    playlist.count,
                                )
                            }}
                            ·
                            {{
                                $t(
                                    playlist.changed
                                        ? 'Changed :ago'
                                        : 'Checked :ago',
                                    { ago: ago(index) ?? '' },
                                )
                            }}
                        </template>
                        <span v-else class="text-alarm">{{
                            $t('Removed from YouTube Music')
                        }}</span>
                    </p>
                </div>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.fragment {
    background: oklch(0.19 0.013 60 / 0.72);
}

.is-removed {
    opacity: 0.7;
}
</style>
