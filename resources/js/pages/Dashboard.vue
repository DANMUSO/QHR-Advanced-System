<script setup>
import { ref, computed, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue';
import { useDocumentVisibility } from '@vueuse/core';
import { RefreshCw, SlidersHorizontal, Check, GripVertical, Eye, EyeOff,
         CalendarPlus, HeartPulse, FileDown, UserPlus, Wallet, Megaphone } from 'lucide-vue-next';
import api from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import { timeAgo } from '@/lib/format';

import WelcomeWidget   from '@/components/dashboard/widgets/Welcome.vue';
import KpiWidget       from '@/components/dashboard/widgets/HeadcountKpis.vue';
import TrendWidget     from '@/components/dashboard/widgets/HeadcountTrend.vue';
import BreakdownWidget from '@/components/dashboard/widgets/WorkforceBreakdown.vue';
import ApprovalsWidget from '@/components/dashboard/widgets/ApprovalsInbox.vue';
import AlertsWidget    from '@/components/dashboard/widgets/HrAlerts.vue';
import ActivityWidget  from '@/components/dashboard/widgets/RecentActivity.vue';
import CelebWidget     from '@/components/dashboard/widgets/Celebrations.vue';
import AnnouncWidget   from '@/components/dashboard/widgets/Announcements.vue';
import OutWidget       from '@/components/dashboard/widgets/WhoIsOut.vue';
import HolidayWidget   from '@/components/dashboard/widgets/UpcomingHolidays.vue';
import SecurityWidget  from '@/components/dashboard/widgets/SecurityStatus.vue';
import TeamWidget      from '@/components/dashboard/widgets/MyTeam.vue';
import AttendWidget    from '@/components/dashboard/widgets/TeamAttendanceToday.vue';

const auth = useAuthStore();
const ui   = useUiStore();

// Only needed for staff/customize dynamic layout
const mods = import.meta.glob('../components/dashboard/widgets/*.vue');
const dynCmp = Object.fromEntries(
    Object.entries(mods).map(([p, l]) => {
        const key = p.split('/').pop().replace('.vue','').replace(/([a-z0-9])([A-Z])/g,'$1_$2').toLowerCase();
        return [key, defineAsyncComponent(l)];
    })
);

const widgets     = ref([]);
const loading     = ref(true);
const refreshing  = ref(false);
const generatedAt = ref(null);
const customizing = ref(false);
const dragKey     = ref(null);
const visibility  = useDocumentVisibility();
let timer;

const isAdmin = computed(() => auth.can('dashboard.hr') || auth.user?.roles?.includes('super-admin'));
const wd = key => widgets.value.find(w => w.key === key)?.data ?? null;

const span = { sm:'lg:col-span-4', md:'lg:col-span-6', lg:'lg:col-span-8', full:'lg:col-span-12' };
const shown = computed(() => widgets.value.filter(w => customizing.value || !w.hidden));

// Widgets the fixed admin layout places itself; any other visible widget (finance, payroll, module widgets) goes in "More".
const FIXED = ['welcome', 'headcount_kpis', 'headcount_trend', 'workforce_breakdown', 'approvals_inbox', 'hr_alerts', 'recent_activity',
    'who_is_out', 'celebrations', 'announcements', 'upcoming_holidays', 'my_team', 'security_status', 'team_attendance_today'];
const extraWidgets = computed(() => widgets.value.filter(w => !FIXED.includes(w.key) && !w.hidden && w.data && dynCmp[w.key]));

const quickActions = computed(() => [
    { label:'Apply for leave',   icon:CalendarPlus, to:'/leave',         can:'leave.apply',          bg:'bg-violet-500' },
    { label:'Submit claim',      icon:HeartPulse,   to:'/claims',        can:'claims.submit',        bg:'bg-rose-500' },
    { label:'My payslips',       icon:FileDown,     to:'/payroll',       can:'payroll.view-own',     bg:'bg-emerald-500' },
    { label:'Add employee',      icon:UserPlus,     to:'/employees',     can:'employees.create',     bg:'bg-sky-500' },
    { label:'Run payroll',       icon:Wallet,       to:'/payroll',       can:'payroll.manage',       bg:'bg-amber-500' },
    { label:'Post announcement', icon:Megaphone,    to:'/announcements', can:'announcements.manage', bg:'bg-brand-500' },
].filter(a => auth.can(a.can)));

async function load(silent = false, background = false) {
    silent ? (refreshing.value = true) : (loading.value = true);
    try {
        // The 5-minute auto refresh is a background request: it must not reset the server-side idle timeout.
        const { data } = await api.get('/dashboard', background ? { headers: { 'X-Background-Request': '1' } } : {});
        widgets.value     = data.widgets;
        generatedAt.value = data.generated_at;
    } finally {
        loading.value    = false;
        refreshing.value = false;
    }
}

async function saveLayout() {
    await auth.savePreferences({
        dashboard: {
            order:  widgets.value.map(w => w.key),
            hidden: widgets.value.filter(w => w.hidden).map(w => w.key),
        },
    });
}

async function toggleHidden(w) {
    w.hidden = !w.hidden;
    if (!w.hidden && !w.data)
        w.data = (await api.get(`/dashboard/widgets/${w.key}`)).data.data;
}

async function finishCustomizing() {
    customizing.value = false;
    await saveLayout();
    ui.toast('Layout saved.');
}

async function resetLayout() {
    await auth.savePreferences({ dashboard: { order:[], hidden:[] } });
    customizing.value = false;
    await load();
    ui.toast('Layout reset.');
}

function onDragStart(key) { dragKey.value = key; }
function onDragOver(key) {
    if (!dragKey.value || dragKey.value === key) return;
    const l = widgets.value, f = l.findIndex(w => w.key === dragKey.value), t = l.findIndex(w => w.key === key);
    l.splice(t, 0, l.splice(f, 1)[0]);
}

onMounted(() => {
    load();
    timer = setInterval(() => visibility.value === 'visible' && !customizing.value && load(true, true), 300_000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
<div class="mx-auto max-w-[1440px] space-y-5">

    <!-- ══════════════════════════════════════════════════════════
         ADMIN FIXED LAYOUT
    ══════════════════════════════════════════════════════════ -->
    <template v-if="isAdmin && !customizing">

        <!-- skeleton -->
        <template v-if="loading">
            <div class="h-[88px] animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800" />
            <div class="grid grid-cols-4 gap-px lg:grid-cols-8">
                <div v-for="i in 8" :key="i" class="h-[90px] animate-pulse bg-slate-100 dark:bg-slate-800" />
            </div>
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div class="h-[320px] animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800 lg:col-span-2" />
                <div class="h-[320px] animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800" />
            </div>
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div v-for="i in 3" :key="i" class="h-[300px] animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800" />
            </div>
        </template>

        <template v-else>

            <!-- ── Row 1: greeting banner ──────────────────────── -->
            <div class="flex items-stretch gap-4">
                <div class="card flex-1 overflow-hidden">
                    <WelcomeWidget v-if="wd('welcome')" :data="wd('welcome')" />
                </div>
                <div class="flex shrink-0 flex-col justify-center gap-2">
                    <button class="btn-secondary !px-3" :disabled="refreshing"
                        aria-label="Refresh dashboard" @click="load(true)">
                        <RefreshCw class="size-4" :class="refreshing && 'animate-spin'" />
                    </button>
                    <button class="btn-secondary !px-3"
                        aria-label="Customize dashboard" @click="customizing = true">
                        <SlidersHorizontal class="size-4" />
                    </button>
                </div>
            </div>

            <!-- ── Row 2: KPI tiles ────────────────────────────── -->
            <div v-if="wd('headcount_kpis')" class="card overflow-hidden">
                <KpiWidget :data="wd('headcount_kpis')" />
            </div>

            <!-- ── Row 3: quick actions ────────────────────────── -->
            <div v-if="quickActions.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <RouterLink v-for="a in quickActions" :key="a.label" :to="a.to"
                    class="card group flex items-center gap-3 px-4 py-3.5 transition hover:border-slate-300 hover:shadow-md">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl text-white shadow-sm" :class="a.bg">
                        <component :is="a.icon" class="size-4" />
                    </span>
                    <span class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ a.label }}</span>
                </RouterLink>
            </div>

            <!-- ── Row 4: charts ───────────────────────────────── -->
            <div class="grid grid-cols-1 items-stretch gap-5 lg:grid-cols-3">
                <div v-if="wd('headcount_trend')" class="card overflow-hidden lg:col-span-2">
                    <TrendWidget :data="wd('headcount_trend')" title="Headcount Trend" @refresh="load(true)" />
                </div>
                <div v-if="wd('workforce_breakdown')" class="card overflow-hidden">
                    <BreakdownWidget :data="wd('workforce_breakdown')" title="Workforce Breakdown" @refresh="load(true)" />
                </div>
            </div>

            <!-- ── Row 5: approvals + alerts + activity ────────── -->
            <div class="grid grid-cols-1 items-stretch gap-5 lg:grid-cols-3">
                <div v-if="wd('approvals_inbox')" class="card relative overflow-hidden">
                    <ApprovalsWidget :data="wd('approvals_inbox')" title="Approvals Inbox" @refresh="load(true)" />
                </div>
                <div v-if="wd('hr_alerts')" class="card overflow-hidden">
                    <AlertsWidget :data="wd('hr_alerts')" title="Needs Attention" @refresh="load(true)" />
                </div>
                <div v-if="wd('recent_activity')" class="card overflow-hidden">
                    <ActivityWidget :data="wd('recent_activity')" title="Recent Activity" @refresh="load(true)" />
                </div>
            </div>

            <!-- ── Row 6: who's out (5) + celebrations (4) + announcements (3) ── -->
            <div class="grid grid-cols-1 items-stretch gap-5 sm:grid-cols-2 lg:grid-cols-12">
                <div v-if="wd('who_is_out')" class="card overflow-hidden lg:col-span-5">
                    <OutWidget :data="wd('who_is_out')" title="Who Is Out" />
                </div>
                <div v-if="wd('celebrations')" class="card overflow-hidden lg:col-span-4">
                    <CelebWidget :data="wd('celebrations')" title="Celebrations" />
                </div>
                <div v-if="wd('announcements')" class="card overflow-hidden lg:col-span-3">
                    <AnnouncWidget :data="wd('announcements')" title="Announcements" />
                </div>
            </div>

            <!-- ── Row 7: holidays + team + security + attendance ─ -->
            <div class="grid grid-cols-1 items-stretch gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <div v-if="wd('upcoming_holidays')" class="card overflow-hidden">
                    <HolidayWidget :data="wd('upcoming_holidays')" title="Upcoming Holidays" />
                </div>
                <div v-if="wd('my_team')" class="card overflow-hidden">
                    <TeamWidget :data="wd('my_team')" title="My Team" />
                </div>
                <div v-if="wd('security_status')" class="card overflow-hidden">
                    <SecurityWidget :data="wd('security_status')" title="Account Security" />
                </div>
                <div v-if="wd('team_attendance_today')" class="card overflow-hidden">
                    <AttendWidget :data="wd('team_attendance_today')" title="Team Attendance" />
                </div>
            </div>

            <!-- ── Row 8: every other widget this user may see (finance, payroll, module widgets) ─ -->
            <div v-if="extraWidgets.length" class="grid grid-flow-row-dense grid-cols-1 items-stretch gap-5 lg:grid-cols-12">
                <section v-for="w in extraWidgets" :key="w.key" class="card flex flex-col overflow-hidden" :class="span[w.size] ?? span.sm">
                    <component :is="dynCmp[w.key]" :data="w.data" :title="w.title" class="flex-1" @refresh="load(true)" />
                </section>
            </div>

        </template>
    </template>

    <!-- ══════════════════════════════════════════════════════════
         STAFF / CUSTOMIZE LAYOUT
    ══════════════════════════════════════════════════════════ -->
    <template v-else>
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Dashboard</h1>
                <p class="mt-0.5 text-sm text-slate-500">
                    {{ auth.user?.company?.name ?? 'All companies' }}
                    <span v-if="generatedAt"> · updated {{ timeAgo(generatedAt) }}</span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button class="btn-secondary !px-3" :disabled="refreshing"
                    aria-label="Refresh dashboard" @click="load(true)">
                    <RefreshCw class="size-4" :class="refreshing && 'animate-spin'" />
                </button>
                <template v-if="customizing">
                    <button class="btn-secondary" @click="resetLayout">Reset</button>
                    <button class="btn-primary" @click="finishCustomizing"><Check class="size-4" /> Done</button>
                </template>
                <button v-else-if="isAdmin" class="btn-secondary" @click="customizing = true">
                    <SlidersHorizontal class="size-4" /> Customize
                </button>
            </div>
        </div>

        <div v-if="quickActions.length && !customizing" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <RouterLink v-for="a in quickActions" :key="a.label" :to="a.to"
                class="card group flex items-center gap-3 px-4 py-3.5 transition hover:shadow-md">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl text-white" :class="a.bg">
                    <component :is="a.icon" class="size-4" />
                </span>
                <span class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ a.label }}</span>
            </RouterLink>
        </div>

        <p v-if="customizing" class="rounded-xl border border-dashed border-brand-300 bg-brand-50/50 px-4 py-3 text-sm text-brand-700 dark:border-brand-500/40 dark:bg-brand-500/10 dark:text-brand-300">
            Drag cards to reorder · eye icon to show/hide · <strong>Done</strong> to save.
        </p>

        <div v-if="loading" class="grid grid-cols-1 gap-5 lg:grid-cols-12">
            <div class="h-32 animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800 lg:col-span-12" />
            <div v-for="i in 6" :key="i" class="h-64 animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800 lg:col-span-4" />
        </div>

        <TransitionGroup v-else tag="div" name="fade" class="grid grid-flow-row-dense grid-cols-1 gap-5 lg:grid-cols-12">
            <section v-for="w in shown" :key="w.key"
                class="card relative flex flex-col overflow-hidden"
                :class="[span[w.size]??span.sm, customizing&&'cursor-move ring-2 ring-dashed ring-brand-300', w.hidden&&'opacity-40', dragKey===w.key&&'opacity-50']"
                :draggable="customizing"
                @dragstart="onDragStart(w.key)" @dragover.prevent="onDragOver(w.key)" @dragend="dragKey=null">
                <div v-if="customizing" class="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-4 py-2 dark:border-slate-800 dark:bg-slate-800/60">
                    <span class="flex items-center gap-2 text-sm font-medium text-slate-600 dark:text-slate-300">
                        <GripVertical class="size-4 text-slate-400" /> {{ w.title }}
                    </span>
                    <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700"
                        :aria-label="w.hidden ? 'Show widget' : 'Hide widget'"
                        @click="toggleHidden(w)">
                        <component :is="w.hidden ? EyeOff : Eye" class="size-4" />
                    </button>
                </div>
                <component v-if="dynCmp[w.key] && w.data" :is="dynCmp[w.key]" :data="w.data" :title="w.title" class="flex-1" @refresh="load(true)" />
                <div v-else-if="!w.data" class="flex flex-1 items-center justify-center gap-2 p-8 text-sm text-slate-400">
                    <EyeOff class="size-4" /> Hidden
                </div>
            </section>
        </TransitionGroup>
    </template>

</div>
</template>
