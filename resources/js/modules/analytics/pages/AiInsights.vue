<script setup>
import { ref, onMounted } from 'vue';
import { RefreshCw, Brain } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';

const ui = useUiStore();
const insights = ref([]);
const loading = ref(true);
const refreshing = ref(false);

const categoryColors = {
    retention: 'red',
    performance: 'blue',
    payroll: 'green',
    leave: 'yellow',
    recruitment: 'purple',
    compliance: 'orange',
};

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/analytics/ai-insights');
        insights.value = data.data ?? data;
    } finally {
        loading.value = false;
    }
}

async function refresh() {
    refreshing.value = true;
    try {
        await api.post('/analytics/ai-insights/refresh');
        ui.toast('AI insights refreshed.');
        await load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        refreshing.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="AI Insights">
            <template #actions>
                <button class="btn-secondary" :disabled="refreshing" @click="refresh">
                    <RefreshCw class="size-4" :class="refreshing ? 'animate-spin' : ''" />
                    {{ refreshing ? 'Refreshing…' : 'Refresh' }}
                </button>
            </template>
        </PageHeader>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else-if="!insights.length" class="card p-10 text-center text-slate-400">
            <Brain class="mx-auto mb-3 size-10 opacity-30" />
            <p>No AI insights available. Click Refresh to generate.</p>
        </div>
        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="insight in insights" :key="insight.id" class="card p-5">
                <div class="mb-2 flex items-start gap-3">
                    <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-100 dark:bg-brand-900/30">
                        <Brain class="size-4 text-brand-600 dark:text-brand-400" />
                    </div>
                    <div>
                        <p class="font-semibold text-slate-900 dark:text-white">{{ insight.title }}</p>
                        <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                            :class="`bg-${categoryColors[insight.category] ?? 'slate'}-100 text-${categoryColors[insight.category] ?? 'slate'}-700 dark:bg-${categoryColors[insight.category] ?? 'slate'}-900/30 dark:text-${categoryColors[insight.category] ?? 'slate'}-300`">
                            {{ insight.category }}
                        </span>
                    </div>
                </div>
                <p class="text-sm text-slate-600 dark:text-slate-400">{{ insight.description }}</p>
                <p v-if="insight.recommendation" class="mt-2 rounded-lg bg-slate-50 p-2 text-xs text-slate-500 dark:bg-slate-800/50">
                    💡 {{ insight.recommendation }}
                </p>
                <p class="mt-2 text-xs text-slate-400">{{ insight.generated_at?.slice(0, 10) }}</p>
            </div>
        </div>
    </div>
</template>
