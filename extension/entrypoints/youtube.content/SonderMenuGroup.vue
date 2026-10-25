<script setup lang="ts">
import { ref } from 'vue';
import type { Action } from '@/lib/youtube/actions';
import type { Target } from '@/lib/youtube/targets';

const props = defineProps<{
    target: Target;
    actions: {
        run(action: Action, target: Target): Promise<void>;
        openPanel(target: Target): void;
    };
}>();

const busy = ref(false);

const commands: { action: Action; label: string; icon: string }[] = [
    { action: 'play_now', label: 'Lire maintenant', icon: '▶' },
    { action: 'play_next', label: 'Lire ensuite', icon: '⤵' },
    { action: 'enqueue', label: 'Ajouter à la file', icon: '＋' },
];

async function run(action: Action): Promise<void> {
    busy.value = true;

    try {
        await props.actions.run(action, props.target);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div
        class="group-enter mx-1.5 my-1 rounded-[9px] bg-[oklch(0.21_0.014_60)] py-1 font-sans text-paper"
        role="group"
        aria-label="Sonder"
    >
        <p
            class="flex items-center gap-2 px-2.5 pt-1 pb-1.5 text-[12.5px] font-bold text-dim"
        >
            <span class="relative size-3 rounded-full border-2 border-amber">
                <span class="absolute inset-0.5 rounded-full bg-amber" />
            </span>
            Sonder
        </p>
        <button
            v-for="command in commands"
            :key="command.action"
            type="button"
            role="menuitem"
            :disabled="busy"
            class="flex w-full cursor-pointer items-center gap-3 rounded-[7px] px-2.5 py-1.5 text-left text-[14px] font-medium transition-colors duration-150 hover:bg-raised focus-visible:bg-raised focus-visible:outline-none disabled:opacity-60"
            @click="run(command.action)"
        >
            <span class="w-4 text-center text-amber" aria-hidden="true">{{
                command.icon
            }}</span>
            <span data-test="label">{{ command.label }}</span>
        </button>
        <button
            type="button"
            role="menuitem"
            class="flex w-full cursor-pointer items-center gap-3 rounded-[7px] px-2.5 py-1.5 text-left text-[14px] font-medium transition-colors duration-150 hover:bg-raised focus-visible:bg-raised focus-visible:outline-none"
            @click="actions.openPanel(target)"
        >
            <span class="w-4 text-center text-amber" aria-hidden="true">◧</span>
            <span data-test="label">Ouvrir le panneau</span>
        </button>
    </div>
</template>

<style scoped>
@media (prefers-reduced-motion: no-preference) {
    .group-enter {
        animation: group-enter 180ms var(--ease-out-quint) both;
    }
}

@keyframes group-enter {
    from {
        opacity: 0;
        transform: translateY(-4px);
    }
}
</style>
