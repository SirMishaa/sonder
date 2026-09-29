<script setup lang="ts">
import { TriangleAlert } from 'lucide-vue-next';
import { formatAgo, formatDateTime } from '@/lib/i18n';

const yesterday = Temporal.Now.instant().subtract({ hours: 24 }).toString();
</script>

<template>
    <div class="flex flex-col gap-3" aria-hidden="true">
        <div class="state rounded-xl border border-line px-4 py-3">
            <p class="text-[12.5px] font-medium text-faint">
                {{ $t('Sidebar, a normal day') }}
            </p>
            <p class="mt-1.5 flex items-center gap-2 text-[13px] font-medium">
                <span class="size-[7px] rounded-full bg-signal" />
                {{ $t('Synced :ago', { ago: formatAgo(2, 'minute') }) }}
            </p>
        </div>

        <div class="state rounded-xl border border-line px-4 py-3">
            <p class="text-[12.5px] font-medium text-faint">
                {{ $t('While a sync runs') }}
            </p>
            <p class="mt-1.5 flex items-center gap-2 text-[13px] font-medium">
                <span class="size-[7px] animate-pulse rounded-full bg-amber" />
                {{ $t('Syncing :done of :total…', { done: '7', total: '17' }) }}
            </p>
            <span class="mt-2.5 block h-1 overflow-hidden rounded-full bg-line">
                <i class="bar block h-full w-[41%]" />
            </span>
        </div>

        <div
            class="alarm flex items-start gap-3 rounded-xl px-4 py-3 text-[13px]"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0 text-alarm" />
            <div>
                <p class="font-bold">
                    {{ $t('Your YouTube Music cookie stopped working') }}
                </p>
                <p class="mt-0.5 text-dim">
                    {{
                        $t('Refused since :date. Paste a fresh cookie below.', {
                            date: formatDateTime(yesterday),
                        })
                    }}
                </p>
            </div>
            <span
                class="ml-auto shrink-0 rounded-md border border-line px-2.5 py-1 text-[12.5px] font-semibold"
                >{{ $t('Update the cookie') }}</span
            >
        </div>
    </div>
</template>

<style scoped>
.state {
    background: oklch(0.19 0.013 60 / 0.72);
}

.bar {
    background: var(--accent-grad);
}

.alarm {
    background: oklch(0.66 0.18 28 / 0.1);
    box-shadow: inset 0 0 0 1px oklch(0.66 0.18 28 / 0.4);
}
</style>
