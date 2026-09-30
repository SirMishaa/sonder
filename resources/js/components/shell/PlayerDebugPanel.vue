<script setup lang="ts">
import { computed } from 'vue';
import { formatSeconds, usePlayer } from '@/composables/usePlayer';

const player = usePlayer();

const VISIBLE_ENTRIES = 50;

const entries = computed(() =>
    player.debug.entries.slice(-VISIBLE_ENTRIES).reverse(),
);
const status = computed(() => {
    if (player.state.unavailable) {
        return 'unavailable';
    }

    if (!player.state.ready) {
        return 'loading';
    }

    return player.state.playing ? 'playing' : 'paused';
});

function timeOf(at: number): string {
    return new Date(at).toLocaleTimeString();
}
</script>

<template>
    <section
        class="rounded-[10px] bg-raised p-3 text-xs"
        :aria-label="$t('Player debug')"
    >
        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
            <dt class="text-faint">{{ $t('State') }}</dt>
            <dd>{{ status }}</dd>
            <dt class="text-faint">{{ $t('Expected video') }}</dt>
            <dd class="font-mono">
                {{ player.diagnostics.value.expectedVideoId ?? '—' }}
            </dd>
            <dt class="text-faint">{{ $t('Playing video') }}</dt>
            <dd class="font-mono">
                {{ player.diagnostics.value.actualVideoId ?? '—' }}
            </dd>
            <dt class="text-faint">{{ $t('Player duration') }}</dt>
            <dd>{{ formatSeconds(player.diagnostics.value.playerDuration) }}</dd>
            <dt class="text-faint">{{ $t('Stored duration') }}</dt>
            <dd>
                {{
                    player.diagnostics.value.storedDuration === null
                        ? '—'
                        : formatSeconds(player.diagnostics.value.storedDuration)
                }}
            </dd>
        </dl>

        <p
            v-if="player.diagnostics.value.isLikelyAd"
            class="mt-2 inline-block rounded-full bg-alarm px-2 py-0.5 font-semibold text-paper"
        >
            {{ $t('Likely ad') }}
        </p>

        <ol
            class="mt-3 max-h-60 space-y-0.5 overflow-y-auto font-mono text-[11px]"
        >
            <li
                v-for="(entry, position) in entries"
                :key="`${entry.at}-${position}`"
                :class="{ 'text-alarm': entry.kind === 'error' }"
            >
                <span class="text-faint">{{ timeOf(entry.at) }}</span>
                {{ entry.kind }} · {{ entry.message }}
            </li>
            <li v-if="entries.length === 0" class="text-faint">
                {{ $t('No events yet.') }}
            </li>
        </ol>
    </section>
</template>
