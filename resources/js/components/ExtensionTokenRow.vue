<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useRelativeTime } from '@/composables/useRelativeTime';

const props = defineProps<{
    token: App.Data.ExtensionTokenData;
}>();

defineEmits<{
    revoke: [tokenId: string];
}>();

const connectedAt = useRelativeTime(() => props.token.createdAt);
const lastUsedAt = useRelativeTime(() => props.token.lastUsedAt);
</script>

<template>
    <li class="flex items-center justify-between gap-4 py-3">
        <div class="text-sm tabular-nums">
            <p>{{ $t('Connected') }} {{ connectedAt }}</p>
            <p class="text-faint">
                {{
                    token.lastUsedAt
                        ? `${$t('Last used')} ${lastUsedAt}`
                        : $t('Never used')
                }}
            </p>
        </div>
        <Button
            variant="secondary"
            size="sm"
            @click="$emit('revoke', token.id)"
        >
            {{ $t('Revoke') }}
        </Button>
    </li>
</template>
