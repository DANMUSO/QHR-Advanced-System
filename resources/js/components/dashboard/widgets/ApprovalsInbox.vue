<script setup>
import { ref } from 'vue';
import { Inbox, Check, X, PartyPopper } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { timeAgo, titleCase } from '@/lib/format';
import WidgetHeader from '../WidgetHeader.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

defineProps({ data: Object, title: String });
const emit = defineEmits(['refresh']);
const ui   = useUiStore();
const busy = ref(null);
const confirmReject = ref(null); // item awaiting reject confirmation

const typeColor = {
    leave:    'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
    claim:    'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
    kpi:      'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    payroll:  'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    overtime: 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    handover: 'bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-300',
};
const tc = t => typeColor[t] ?? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400';

async function approve(item) {
    busy.value = item.id + 'approve';
    try {
        await api.post(item.approve_endpoint);
        ui.toast(`${titleCase(item.type)} approved.`);
        emit('refresh');
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { busy.value = null; }
}

async function confirmAndReject(item) {
    confirmReject.value = item;
}

async function doReject() {
    const item = confirmReject.value;
    confirmReject.value = null;
    busy.value = item.id + 'reject';
    try {
        await api.post(item.reject_endpoint, { reason: 'Rejected from dashboard' });
        ui.toast(`${titleCase(item.type)} rejected.`);
        emit('refresh');
    } catch (e) { ui.toast(errorMessage(e), 'error'); }
    finally { busy.value = null; }
}
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="Inbox"
            icon-class="bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400"
            :subtitle="data.total ? `${data.total} pending` : 'All clear'" />

        <div v-if="Object.keys(data.by_type||{}).length" class="flex flex-wrap gap-1.5 px-5 py-3">
            <span v-for="(n, t) in data.by_type" :key="t"
                class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="tc(t)">
                {{ titleCase(t) }} · {{ n }}
            </span>
        </div>

        <EmptyState v-if="!data.items.length" :icon="PartyPopper"
            title="All caught up" description="No pending approvals." />

        <ul v-else class="flex-1 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
            <li v-for="item in data.items" :key="item.type+item.id"
                class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                <span class="shrink-0 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="tc(item.type)">
                    {{ titleCase(item.type) }}
                </span>
                <RouterLink :to="item.url" class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-slate-800 hover:text-brand-600 dark:text-slate-100">
                        {{ item.title }}
                    </p>
                    <p class="truncate text-xs text-slate-500">{{ item.subtitle }} · {{ timeAgo(item.requested_at) }}</p>
                </RouterLink>
                <div v-if="item.approve_endpoint" class="flex shrink-0 gap-1">
                    <button aria-label="Approve"
                        class="rounded-lg p-1.5 text-emerald-600 transition hover:bg-emerald-50 disabled:opacity-40 dark:hover:bg-emerald-500/10"
                        :disabled="!!busy" @click="approve(item)">
                        <Check class="size-4" />
                    </button>
                    <button aria-label="Reject"
                        class="rounded-lg p-1.5 text-rose-500 transition hover:bg-rose-50 disabled:opacity-40 dark:hover:bg-rose-500/10"
                        :disabled="!!busy" @click="confirmAndReject(item)">
                        <X class="size-4" />
                    </button>
                </div>
            </li>
        </ul>

        <!-- Reject confirmation -->
        <Transition name="fade">
            <div v-if="confirmReject"
                class="absolute inset-0 z-10 flex items-center justify-center bg-white/90 p-6 dark:bg-slate-900/90">
                <div class="w-full max-w-xs rounded-2xl border border-slate-200 bg-white p-5 shadow-lg dark:border-slate-700 dark:bg-slate-800">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">Reject this request?</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        "{{ confirmReject.title }}" will be rejected.
                    </p>
                    <div class="mt-4 flex justify-end gap-2">
                        <button class="btn-secondary text-xs" @click="confirmReject = null">Cancel</button>
                        <button class="btn-danger text-xs" @click="doReject">Reject</button>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>
