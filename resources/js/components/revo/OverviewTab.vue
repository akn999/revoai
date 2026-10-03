<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { get, type Overview } from '@/lib/revo/api';
import { t } from '@/lib/revo/i18n';

const data = ref<Overview | null>(null);
const emit = defineEmits<{ loaded: [Overview] }>();
let timer: ReturnType<typeof setInterval> | undefined;

async function load() {
    const response = await get<{ data: Overview }>('/overview');
    data.value = response.data;
    emit('loaded', response.data);

    if (response.data.sync.finished && timer) {
        clearInterval(timer);
        timer = undefined;
    }
}

onMounted(async () => {
    await load();

    if (data.value && !data.value.sync.finished) {
        timer = setInterval(load, 5000);
    }
});

onBeforeUnmount(() => timer && clearInterval(timer));

const cards = (d: Overview) => [
    { label: t('overview.products'), value: d.products.total },
    { label: t('overview.missingSeo'), value: d.products.missing_seo },
    { label: t('overview.draftsWaiting'), value: d.content.drafts_waiting },
    { label: t('overview.imageDrafts'), value: d.images.drafts_waiting },
    { label: t('overview.library'), value: d.images.library },
    { label: t('overview.expiring'), value: d.images.expiring_soon },
    { label: t('overview.spent'), value: d.credits.spent_this_month },
];
</script>

<template>
    <div v-if="!data" class="animate-pulse space-y-3">
        <div class="h-24 rounded-lg bg-neutral-200 dark:bg-neutral-800" />
        <div class="h-24 rounded-lg bg-neutral-200 dark:bg-neutral-800" />
    </div>
    <div v-else class="space-y-4">
        <div v-if="!data.sync.finished" class="rounded-lg border p-4">
            <p class="font-medium">{{ t('overview.syncing') }}</p>
            <progress class="mt-2 w-full" :value="data.sync.pages_done" :max="data.sync.total_pages ?? 1" />
        </div>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div v-for="card in cards(data)" :key="card.label" class="rounded-lg border p-4">
                <p class="text-sm text-neutral-500">{{ card.label }}</p>
                <p class="mt-1 text-2xl font-semibold">{{ card.value }}</p>
            </div>
        </div>
    </div>
</template>
