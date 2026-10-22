<script setup>
import { ref, computed, onMounted } from 'vue';
import { Download, FileText } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { formatMoney } from '@/lib/format';
import { openServerFile } from '@/lib/export';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';

/** My payslips (approved / paid runs) with a PDF per payslip, and the P9A tax card per year. */
const rows = ref([]);
const years = ref([]);
const year = ref('');
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const p9aYear = ref(new Date().getFullYear() - 1);

const p9aYears = computed(() => {
    const now = new Date().getFullYear();
    const list = years.value.length ? years.value.map(Number) : [now - 1, now];
    return [...new Set([...list, now])].sort((a, b) => b - a);
});

async function load(p = 1) {
    loading.value = true;
    try {
        const { data } = await api.get('/payroll/my-payslips', { params: { page: p, per_page: 12, year: year.value || undefined } });
        rows.value = data.data ?? [];
        years.value = data.years ?? years.value;
        page.value = data.current_page ?? 1;
        lastPage.value = data.last_page ?? 1;
        if (!p9aYears.value.includes(p9aYear.value)) p9aYear.value = p9aYears.value[0];
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}

const pdf = (row) => openServerFile(`/api/payroll/my-payslips/${row.id}/pdf`);
const p9a = () => openServerFile(`/api/payroll/my-p9a/${p9aYear.value}`);

onMounted(load);
</script>

<template>
    <div>
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex items-center gap-2">
                <label class="text-sm text-slate-500">Year</label>
                <select v-model="year" class="input w-32" @change="load(1)"><option value="">All</option><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
            </div>
            <div class="card flex flex-wrap items-center gap-2 p-3">
                <FileText class="size-4 text-slate-400" />
                <span class="text-sm font-medium text-slate-700 dark:text-slate-200">P9A tax card</span>
                <select v-model="p9aYear" class="input w-28 !py-1.5"><option v-for="y in p9aYears" :key="y" :value="y">{{ y }}</option></select>
                <button class="btn-secondary !py-1.5" @click="p9a"><Download class="size-4" /> Download</button>
            </div>
        </div>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <template v-else>
            <!-- Desktop table -->
            <div class="card hidden overflow-x-auto md:block">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr><th class="px-4 py-3 text-left">Period</th><th class="px-4 py-3 text-left">Pay date</th><th class="px-4 py-3 text-right">Gross</th><th class="px-4 py-3 text-right">Deductions</th><th class="px-4 py-3 text-right">Net pay</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3" /></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="p in rows" :key="p.id" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-medium">{{ p.run?.period_label ?? p.run?.title }}<span v-if="p.run?.type && p.run.type !== 'regular'" class="ml-1 text-xs text-slate-400">({{ p.run.type.replace('_', ' ') }})</span></td>
                            <td class="px-4 py-3 text-slate-500">{{ p.run?.pay_date ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">{{ formatMoney(p.gross_pay) }}</td>
                            <td class="px-4 py-3 text-right">{{ formatMoney(p.total_deductions) }}</td>
                            <td class="px-4 py-3 text-right font-bold">{{ formatMoney(p.net_pay) }}</td>
                            <td class="px-4 py-3"><Badge :color="p.run?.status === 'paid' ? 'green' : 'blue'">{{ p.run?.status }}</Badge></td>
                            <td class="px-4 py-3 text-right"><button class="btn-ghost !py-1 text-xs" @click="pdf(p)"><Download class="size-4" /> PDF</button></td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="7" class="px-4 py-10 text-center text-slate-400">No payslips available yet.</td></tr>
                    </tbody>
                </table>
            </div>
            <!-- Mobile cards -->
            <div class="space-y-3 md:hidden">
                <div v-for="p in rows" :key="p.id" class="card p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div><p class="font-medium text-slate-900 dark:text-white">{{ p.run?.period_label ?? p.run?.title }}</p><p class="text-xs text-slate-500">Paid {{ p.run?.pay_date ?? '—' }}</p></div>
                        <Badge :color="p.run?.status === 'paid' ? 'green' : 'blue'">{{ p.run?.status }}</Badge>
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                        <div><p class="text-slate-400">Gross</p><p class="font-medium">{{ formatMoney(p.gross_pay) }}</p></div>
                        <div><p class="text-slate-400">Deductions</p><p class="font-medium">{{ formatMoney(p.total_deductions) }}</p></div>
                        <div><p class="text-slate-400">Net</p><p class="font-bold">{{ formatMoney(p.net_pay) }}</p></div>
                    </div>
                    <button class="btn-secondary mt-3 w-full !py-1.5" @click="pdf(p)"><Download class="size-4" /> Download payslip</button>
                </div>
                <div v-if="!rows.length" class="card p-10 text-center text-slate-400">No payslips available yet.</div>
            </div>
            <div v-if="lastPage > 1" class="mt-4 flex items-center justify-center gap-2 text-sm">
                <button class="btn-secondary !py-1" :disabled="page <= 1" @click="load(page - 1)">Previous</button>
                <span class="text-slate-500">Page {{ page }} of {{ lastPage }}</span>
                <button class="btn-secondary !py-1" :disabled="page >= lastPage" @click="load(page + 1)">Next</button>
            </div>
        </template>
    </div>
</template>
