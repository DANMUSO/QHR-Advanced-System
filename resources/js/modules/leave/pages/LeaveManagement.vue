<script setup>
/**
 * Leave management (HR-FEATURES §3.5): approvals, staff on leave, year planner, recall, balances & ledger, and
 * administration. Supervisors see their team; leave administrators the company. ?tab= deep-links a tab.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import ApprovalsPanel from '../components/ApprovalsPanel.vue';
import OnLeavePanel from '../components/OnLeavePanel.vue';
import PlannerPanel from '../components/PlannerPanel.vue';
import RecallPanel from '../components/RecallPanel.vue';
import BalancesPanel from '../components/BalancesPanel.vue';
import AdminPanel from '../components/AdminPanel.vue';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const counts = ref({});
const recallPanel = ref(null);

const isAdmin = auth.can('leave.manage');
const tabs = computed(() => [
    { key: 'approvals', label: 'Approvals', count: counts.value.approvals },
    { key: 'on-leave', label: 'On Leave' },
    { key: 'planner', label: 'Planner' },
    { key: 'recall', label: 'Recall' },
    ...(isAdmin ? [{ key: 'balances', label: 'Balances & Ledger' }, { key: 'admin', label: 'Admin' }] : []),
]);

// Old links: ?tab=runs → Admin (run history); ?tab=on-leave-today → On Leave.
const aliases = { runs: 'admin', upload: 'admin', reconcile: 'admin', 'on-leave-today': 'on-leave', ledger: 'balances' };
function fromQuery() {
    const q = String(route.query.tab ?? '');
    const key = aliases[q] ?? q;
    return tabs.value.some(t => t.key === key) ? key : 'approvals';
}
const tab = ref(fromQuery());
const adminSection = computed(() => (route.query.tab === 'runs' ? 'runs' : ''));

watch(tab, (t) => { if (route.query.tab !== t) router.replace({ query: { ...route.query, tab: t } }); });
watch(() => route.query.tab, () => { tab.value = fromQuery(); });

async function loadCounts() {
    try { counts.value = (await api.get('/leave/counts')).data; } catch { /* badges are optional */ }
}

function recall(request) {
    tab.value = 'recall';
    setTimeout(() => recallPanel.value?.open(request), 0);
}

onMounted(loadCounts);
</script>

<template>
    <div>
        <PageHeader title="Leave Management" :subtitle="isAdmin ? 'All staff in the company' : 'Your team'" />
        <Tabs v-model="tab" :tabs="tabs" />

        <ApprovalsPanel v-if="tab === 'approvals'" @changed="loadCounts" />
        <OnLeavePanel v-else-if="tab === 'on-leave'" @recall="recall" />
        <PlannerPanel v-else-if="tab === 'planner'" />
        <RecallPanel v-else-if="tab === 'recall'" ref="recallPanel" />
        <BalancesPanel v-else-if="tab === 'balances'" />
        <AdminPanel v-else-if="tab === 'admin'" :section="adminSection" />
    </div>
</template>
