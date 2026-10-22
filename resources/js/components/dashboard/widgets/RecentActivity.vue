<script setup>
import { Activity } from 'lucide-vue-next';
import { useAuthStore } from '@/stores/auth';
import { timeAgo, titleCase } from '@/lib/format';
import WidgetHeader from '../WidgetHeader.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Avatar from '@/components/ui/Avatar.vue';

defineProps({ data: Object, title: String });
const auth = useAuthStore();

const modColor = {
    employees: 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300',
    payroll:   'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    leave:     'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    claims:    'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
    documents: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
};
const mc = l => modColor[l?.toLowerCase()] ?? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400';
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="Activity"
            icon-class="bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400"
            :to="auth.can('audit.view') ? '/admin/audit-log' : null" />
        <EmptyState v-if="!data.items.length" :icon="Activity" title="No recent activity" />
        <ul v-else class="flex-1 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
            <li v-for="a in data.items" :key="a.id"
                class="flex items-start gap-3 px-5 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                <Avatar :name="a.causer" size="xs" class="mt-0.5 shrink-0" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm text-slate-700 dark:text-slate-300">
                        <span class="font-semibold text-slate-900 dark:text-white">{{ a.causer }}</span>
                        {{ ' ' }}{{ a.event ? `${a.event} ${a.subject.toLowerCase()}` : a.description.charAt(0).toLowerCase() + a.description.slice(1) }}
                    </p>
                    <div class="mt-1 flex items-center gap-2">
                        <span class="text-xs text-slate-400">{{ timeAgo(a.at) }}</span>
                        <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold" :class="mc(a.log)">
                            {{ titleCase(a.log) }}
                        </span>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
