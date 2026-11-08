<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CloudOff, RotateCw } from 'lucide-vue-next';
import { ref } from 'vue';

/** The catalog could not answer: what happened and a way to try again. */
defineProps<{
    message: string;
}>();

const retrying = ref(false);

function retry(): void {
    router.reload({
        onStart: () => {
            retrying.value = true;
        },
        onFinish: () => {
            retrying.value = false;
        },
    });
}
</script>

<template>
    <div
        class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-line px-6 py-12 text-center"
        role="alert"
    >
        <CloudOff class="size-7 text-faint" aria-hidden="true" />
        <p class="max-w-[52ch] font-semibold text-balance">{{ message }}</p>
        <button
            type="button"
            class="inline-flex items-center gap-2 rounded-[9px] border border-line bg-panel/80 px-3.5 py-2 font-semibold transition-[border-color,transform] duration-150 hover:border-[oklch(0.38_0.014_60)] active:scale-[0.97] disabled:scale-[0.97] disabled:cursor-wait disabled:opacity-60"
            :disabled="retrying"
            :aria-busy="retrying"
            @click="retry"
        >
            <RotateCw class="size-4" aria-hidden="true" />
            {{ $t('Retry') }}
        </button>
    </div>
</template>
