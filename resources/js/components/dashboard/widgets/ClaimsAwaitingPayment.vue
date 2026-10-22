<script setup>
import { Banknote } from 'lucide-vue-next';
import { formatMoney, formatDate } from '@/lib/format';
import WidgetHeader from '../WidgetHeader.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

/** Data from App\Modules\Claims\Dashboard\ClaimsAwaitingPaymentWidget: { total, amount, items[] }. */
defineProps({ data: Object, title: String });
</script>

<template>
    <div class="flex h-full flex-col pb-2">
        <WidgetHeader :title="title" :icon="Banknote" to="/claims/payments" link-label="Pay claims"
            icon-class="bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400"
            :subtitle="data.total ? `${data.total} approved · ${formatMoney(data.amount)} to pay` : 'Nothing waiting'" />
        <EmptyState v-if="!data.items?.length" :icon="Banknote" title="No claims awaiting payment" />
        <ul v-else class="flex-1 divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="c in data.items" :key="c.id">
                <RouterLink to="/claims/payments" class="flex items-center gap-3 px-5 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ c.employee_name || 'Unknown employee' }}</p>
                        <p class="truncate text-xs text-slate-500">{{ c.reference }}<span v-if="c.approved_at"> · approved {{ formatDate(c.approved_at) }}</span></p>
                    </div>
                    <span class="shrink-0 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ formatMoney(c.amount) }}</span>
                </RouterLink>
            </li>
        </ul>
    </div>
</template>
