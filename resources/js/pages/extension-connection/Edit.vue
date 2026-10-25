<script setup lang="ts">
import { Head, http, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ExtensionConnectionController from '@/actions/App/Http/Controllers/ExtensionConnectionController';
import ExtensionTokenRow from '@/components/ExtensionTokenRow.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

defineOptions({ layout: AppLayout });

defineProps<{
    tokens: App.Data.ExtensionTokenData[];
}>();

const connecting = ref(false);
const connected = ref(false);
const failed = ref(false);

/**
 * Creates a token and hands it to the extension, whose content script on
 * this page listens for the message. The token never leaves this window.
 */
async function connect(): Promise<void> {
    connecting.value = true;
    failed.value = false;

    try {
        const response = await http.getClient().request({
            method: 'post',
            url: ExtensionConnectionController.store().url,
            headers: { Accept: 'application/json' },
        });
        const { token } = JSON.parse(response.data) as { token: string };

        window.postMessage(
            { type: 'sonder:extension-token', token },
            window.location.origin,
        );
        connected.value = true;
        router.reload({ only: ['tokens'] });
    } catch {
        failed.value = true;
    } finally {
        connecting.value = false;
    }
}

function revoke(tokenId: string): void {
    router.delete(ExtensionConnectionController.destroy(tokenId).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="$t('Browser extension')" />

    <h1 class="sr-only">{{ $t('Browser extension') }}</h1>

    <SettingsLayout>
        <div class="space-y-6">
            <Heading
                variant="small"
                :title="$t('Browser extension')"
                :description="
                    $t(
                        'Send YouTube videos and mixes to Sonder from the extension.',
                    )
                "
            />

            <div class="flex items-center gap-4">
                <Button
                    :disabled="connecting"
                    data-test="connect-extension-button"
                    @click="connect"
                >
                    {{ $t('Connect the extension') }}
                </Button>

                <p v-if="connected" class="text-sm text-dim">
                    {{ $t('Extension connected.') }}
                </p>
                <p v-if="failed" class="text-sm text-alarm">
                    {{ $t('The connection failed. Try again.') }}
                </p>
            </div>

            <ul v-if="tokens.length > 0" class="divide-y divide-line/50">
                <ExtensionTokenRow
                    v-for="token in tokens"
                    :key="token.id"
                    :token="token"
                    @revoke="revoke"
                />
            </ul>
        </div>
    </SettingsLayout>
</template>
