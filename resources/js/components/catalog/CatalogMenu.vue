<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Copy, Ellipsis, ExternalLink } from 'lucide-vue-next';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useToast } from '@/composables/useToast';

/** A catalog page's ⋯ menu: open the page in YouTube Music, copy its link. */
type Props = {
    youTubeMusicUrl: string;
};

defineProps<Props>();

const { toast } = useToast();

async function copyLink(): Promise<void> {
    try {
        await navigator.clipboard.writeText(window.location.href);
        toast(trans('Link copied'));
    } catch {
        toast(trans('Could not copy the link'));
    }
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger
            class="action-button icon"
            :aria-label="$t('More')"
            :title="$t('More')"
        >
            <Ellipsis class="size-4" />
        </DropdownMenuTrigger>
        <DropdownMenuContent
            class="min-w-52 rounded-lg"
            align="start"
            :side-offset="6"
        >
            <DropdownMenuItem as-child>
                <a
                    :href="youTubeMusicUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="cursor-pointer"
                >
                    <ExternalLink class="size-4" />
                    {{ $t('Open in YouTube Music') }}
                </a>
            </DropdownMenuItem>
            <DropdownMenuItem class="cursor-pointer" @select="copyLink">
                <Copy class="size-4" />
                {{ $t('Copy link') }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
