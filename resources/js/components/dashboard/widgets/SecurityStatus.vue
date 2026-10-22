<script setup>
import { computed } from 'vue';
import { ShieldCheck, CheckCircle2, AlertCircle, MonitorSmartphone } from 'lucide-vue-next';
import WidgetHeader from '../WidgetHeader.vue';

const props = defineProps({ data: Object, title: String });
const ring  = computed(() => { const c = 2*Math.PI*34; return { c, offset: c-(props.data.score/100)*c }; });
const color = computed(() => props.data.score>=100 ? '#10b981' : props.data.score>=60 ? '#f59e0b' : '#f43f5e');
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="ShieldCheck"
            icon-class="bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400"
            to="/account/security" link-label="Manage" />
        <div class="flex flex-1 items-center gap-5 px-5 py-4">
            <div class="relative size-20 shrink-0">
                <svg class="size-20 -rotate-90" viewBox="0 0 80 80">
                    <circle cx="40" cy="40" r="34" fill="none" stroke-width="7" class="stroke-slate-100 dark:stroke-slate-800" />
                    <circle cx="40" cy="40" r="34" fill="none" stroke-width="7" stroke-linecap="round"
                        :stroke="color" :stroke-dasharray="ring.c" :stroke-dashoffset="ring.offset"
                        class="transition-all duration-700" />
                </svg>
                <span class="absolute inset-0 flex items-center justify-center text-lg font-bold text-slate-900 dark:text-white">
                    {{ data.score }}%
                </span>
            </div>
            <ul class="flex-1 space-y-2">
                <li v-for="c in data.checks" :key="c.label" class="flex items-start gap-2 text-sm">
                    <CheckCircle2 v-if="c.ok" class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                    <AlertCircle  v-else      class="mt-0.5 size-4 shrink-0 text-amber-500" />
                    <span :class="c.ok ? 'text-slate-500 dark:text-slate-400' : 'font-medium text-slate-800 dark:text-slate-200'">
                        {{ c.label }}
                    </span>
                </li>
            </ul>
        </div>
        <p class="flex items-center gap-1.5 border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800">
            <MonitorSmartphone class="size-3.5" />
            {{ data.active_sessions }} active session{{ data.active_sessions===1?'':'s' }}
        </p>
    </div>
</template>
