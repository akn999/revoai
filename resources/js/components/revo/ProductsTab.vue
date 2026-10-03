<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { ApiError, get, post, type Generation, type Paginated, type ProductSummary } from '@/lib/revo/api';
import { t } from '@/lib/revo/i18n';

const props = defineProps<{ languages: string[] }>();
const emit = defineEmits<{ changed: [] }>();

const FIELDS = ['name', 'description', 'promotion_title', 'subtitle', 'metadata_title', 'metadata_description', 'metadata_url', 'alt'];

const products = ref<ProductSummary[]>([]);
const search = ref('');
const filter = ref('');
const loading = ref(true);
const error = ref('');
const quote = ref(8);

const selected = ref<ProductSummary | null>(null);
const lang = ref(props.languages[0] ?? 'ar');
const fields = ref<string[]>(['name', 'description']);
const keywords = ref('');
const instruction = ref('');
const generation = ref<Generation | null>(null);
const edits = ref<Record<string, string>>({});
const busy = ref(false);
const bulk = ref<{ estimate: number; units: number } | null>(null);

async function load() {
    loading.value = true;
    const query = new URLSearchParams({ search: search.value, ...(filter.value ? { filter: filter.value } : {}) });
    const page = await get<Paginated<ProductSummary>>(`/products?${query}`);
    products.value = page.data;
    loading.value = false;
}

let debounce: ReturnType<typeof setTimeout>;
watch([search, filter], () => {
    clearTimeout(debounce);
    debounce = setTimeout(load, 300);
});

onMounted(async () => {
    await load();
    const q = await get<{ data: { product_content: { credits: number } } }>('/products/quote');
    quote.value = q.data.product_content.credits;
});

const totalCost = computed(() => quote.value);

function open(product: ProductSummary) {
    selected.value = product;
    generation.value = null;
    edits.value = {};
    error.value = '';
}

function message(e: unknown): string {
    if (e instanceof ApiError) {
        const first = Object.values((e.body.errors as Record<string, string[]>) ?? {})[0]?.[0];
        return first ?? e.message;
    }
    return t('common.error');
}

async function poll(id: number) {
    for (let i = 0; i < 90; i++) {
        const res = await get<{ data: Generation }>(`/generations/${id}`);
        generation.value = res.data;

        if (!['queued', 'running'].includes(res.data.status)) {
            return;
        }

        await new Promise((resolve) => setTimeout(resolve, 2000));
    }
}

async function generate() {
    if (!selected.value) return;
    busy.value = true;
    error.value = '';
    try {
        const res = await post<{ data: Generation }>(`/products/${selected.value.id}/generate`, {
            lang: lang.value,
            fields: fields.value,
            keywords: keywords.value || null,
            instruction: instruction.value || null,
        });
        generation.value = res.data;
        await poll(res.data.id);
        emit('changed');
    } catch (e) {
        error.value = message(e);
    } finally {
        busy.value = false;
    }
}

async function approve(only?: string[], acknowledge = false) {
    if (!generation.value) return;
    busy.value = true;
    error.value = '';
    try {
        const res = await post<{ data: Generation }>(`/generations/${generation.value.id}/approve`, {
            fields: only ?? null,
            edits: edits.value,
            acknowledge_outdated: acknowledge,
        });
        generation.value = res.data;
        await load();
        emit('changed');
    } catch (e) {
        error.value = message(e);
    } finally {
        busy.value = false;
    }
}

async function reject() {
    if (!generation.value) return;
    generation.value = (await post<{ data: Generation }>(`/generations/${generation.value.id}/reject`)).data;
    emit('changed');
}

async function resync() {
    if (selected.value) await post(`/products/${selected.value.id}/resync`);
}

async function estimateBulk() {
    const res = await post<{ data: { estimate: number; units: number } }>('/bulk/estimate', { filter: { type: filter.value === 'missing_seo' ? 'missing_seo' : 'all' }, languages: [lang.value] });
    bulk.value = res.data;
}

async function startBulk() {
    error.value = '';
    try {
        await post('/bulk', { filter: { type: filter.value === 'missing_seo' ? 'missing_seo' : 'all' }, fields: fields.value, languages: [lang.value] });
        bulk.value = null;
        emit('changed');
    } catch (e) {
        error.value = message(e);
    }
}

const limit = (field: string) => generation.value?.limits?.[field];
const value = (field: string) => edits.value[field] ?? generation.value?.output?.[field] ?? '';
const tooLong = (field: string) => limit(field) !== undefined && value(field).length > (limit(field) as number);
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <input v-model="search" type="search" class="rounded border px-3 py-2" :placeholder="t('common.search')" />
            <select v-model="filter" class="rounded border px-3 py-2">
                <option value="">{{ t('common.all') }}</option>
                <option value="missing_seo">{{ t('products.filter.missing_seo') }}</option>
                <option value="stale">{{ t('products.filter.stale') }}</option>
            </select>
            <button class="rounded border px-3 py-2" @click="estimateBulk">{{ t('products.bulk') }}</button>
        </div>

        <div v-if="bulk" class="rounded-lg border p-4">
            <p>{{ t('products.bulkEstimate', { n: bulk.estimate, units: bulk.units }) }}</p>
            <button class="mt-2 rounded bg-neutral-900 px-3 py-2 text-white dark:bg-white dark:text-neutral-900" @click="startBulk">{{ t('products.bulkStart') }}</button>
        </div>

        <p v-if="error" class="rounded border border-red-300 bg-red-50 p-3 text-red-800" role="alert">{{ error }}</p>

        <div v-if="loading" class="animate-pulse space-y-2"><div v-for="n in 4" :key="n" class="h-14 rounded bg-neutral-200 dark:bg-neutral-800" /></div>
        <p v-else-if="!products.length" class="text-neutral-500">{{ t('common.empty') }}</p>
        <ul v-else class="divide-y rounded-lg border">
            <li v-for="product in products" :key="product.id" class="flex items-center gap-3 p-3">
                <img v-if="product.image" :src="product.image" alt="" class="size-12 rounded object-cover" />
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ product.name ?? product.sku }}</p>
                    <p class="text-sm text-neutral-500">{{ product.sku }} · {{ product.price }} {{ product.currency }}</p>
                </div>
                <span v-if="!product.has_seo" class="rounded bg-amber-100 px-2 py-1 text-xs text-amber-800">{{ t('products.filter.missing_seo') }}</span>
                <button class="rounded border px-3 py-1" @click="open(product)">{{ t('common.generate') }}</button>
            </li>
        </ul>

        <div v-if="selected" class="space-y-3 rounded-lg border p-4">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold">{{ selected.name ?? selected.sku }}</h3>
                <button class="text-sm underline" @click="resync">{{ t('products.resync') }}</button>
            </div>

            <template v-if="!generation">
                <label class="block text-sm">{{ t('products.language') }}
                    <select v-model="lang" class="mt-1 block rounded border px-3 py-2"><option v-for="l in languages" :key="l" :value="l">{{ l }}</option></select>
                </label>
                <fieldset>
                    <legend class="text-sm">{{ t('products.fields') }}</legend>
                    <label v-for="f in FIELDS" :key="f" class="me-4 inline-flex items-center gap-1">
                        <input v-model="fields" type="checkbox" :value="f" /> {{ t(`field.${f}`) }}
                    </label>
                </fieldset>
                <input v-model="keywords" class="block w-full rounded border px-3 py-2" :placeholder="t('products.keywords')" />
                <input v-model="instruction" class="block w-full rounded border px-3 py-2" :placeholder="t('products.instruction')" />
                <p class="text-sm text-neutral-500">{{ t('products.quote', { n: totalCost }) }}</p>
                <button :disabled="busy || !fields.length" class="rounded bg-neutral-900 px-4 py-2 text-white disabled:opacity-50 dark:bg-white dark:text-neutral-900" @click="generate">{{ busy ? t('app.loading') : t('common.generate') }}</button>
            </template>

            <template v-else>
                <p class="text-sm text-neutral-500">{{ t('products.status') }}: {{ generation.status }}</p>
                <p v-if="generation.error" class="text-red-700">{{ generation.error }}</p>
                <p v-if="generation.outdated" class="rounded bg-amber-50 p-2 text-amber-800">{{ t('products.outdated') }}</p>

                <div v-for="f in generation.pending_fields" :key="f" class="space-y-1">
                    <label class="text-sm font-medium" :for="`f-${f}`">{{ t(`field.${f}`) }}
                        <span v-if="limit(f)" class="text-neutral-500" :class="{ 'text-red-600': tooLong(f) }">({{ value(f).length }}/{{ limit(f) }})</span>
                    </label>
                    <textarea v-if="generation.status === 'draft' && f !== 'alt'" :id="`f-${f}`" :value="value(f)" rows="3" class="block w-full rounded border px-3 py-2" @input="edits[f] = ($event.target as HTMLTextAreaElement).value" />
                    <button v-if="generation.status === 'draft'" :disabled="busy || tooLong(f)" class="rounded border px-2 py-1 text-sm" @click="approve([f], generation.outdated)">{{ t('common.approve') }}</button>
                </div>

                <div v-if="generation.status === 'draft'" class="flex gap-2">
                    <button :disabled="busy" class="rounded bg-neutral-900 px-4 py-2 text-white dark:bg-white dark:text-neutral-900" @click="approve(undefined, generation.outdated)">{{ generation.outdated ? t('products.acknowledge') : t('common.approve') }}</button>
                    <button :disabled="busy" class="rounded border px-4 py-2" @click="reject">{{ t('common.reject') }}</button>
                </div>
                <button class="text-sm underline" @click="generation = null">{{ t('common.cancel') }}</button>
            </template>
        </div>
    </div>
</template>
