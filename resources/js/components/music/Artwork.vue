<script setup lang="ts">
import { ListMusic } from 'lucide-vue-next';
import { ref, watch } from 'vue';

type Props = {
    src: string | null;
    alt?: string;
    eager?: boolean;
};

const props = withDefaults(defineProps<Props>(), { alt: '', eager: false });

const loaded = ref(false);

watch(
    () => props.src,
    () => {
        loaded.value = false;
    },
);
</script>

<template>
    <div class="relative overflow-hidden bg-raised">
        <img
            v-if="src"
            :src="src"
            :alt="alt"
            :loading="eager ? 'eager' : 'lazy'"
            decoding="async"
            class="size-full object-cover transition-[opacity,transform] duration-[420ms] ease-out-quint"
            :class="loaded ? 'opacity-100' : 'opacity-0'"
            @load="loaded = true"
        />
        <div v-else class="flex size-full items-center justify-center">
            <ListMusic class="size-1/3 text-faint" />
        </div>
    </div>
</template>
