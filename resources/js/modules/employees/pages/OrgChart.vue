<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import { useRouter } from 'vue-router';
import { onClickOutside, useEventListener } from '@vueuse/core';
import {
    Search, ZoomIn, ZoomOut, Maximize2, ChevronsDown, ChevronsUp, Printer, Building2, Users, Network, GitBranch,
    Mail, Phone, MapPin, CalendarDays, X, ExternalLink, UserRound, RotateCcw,
} from 'lucide-vue-next';
import api from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import { formatDate } from '@/lib/format';
import PageHeader from '@/components/ui/PageHeader.vue';
import Avatar from '@/components/ui/Avatar.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import OrgChartNode from '../components/OrgChartNode.vue';

const router = useRouter();
const auth = useAuthStore();

const roots = ref([]);
const loading = ref(true);
const expanded = ref(new Set());
const zoom = ref(1);
const selected = ref(null);
const highlightId = ref(null);
const departmentFilter = ref('');
const query = ref('');
const searchOpen = ref(false);
const searchBox = ref(null);
const canvas = ref(null);
const chart = ref(null);

// ── Data ──────────────────────────────────────────────────────────────────────────────────────
const byId = new Map();
const parentOf = new Map();

function index(nodes, parent = null, depth = 0) {
    let total = 0;
    for (const n of nodes) {
        byId.set(n.id, n);
        parentOf.set(n.id, parent);
        n.depth = depth;
        n.total = n.children.length + index(n.children, n.id, depth + 1);
        total += n.total;
    }
    return total;
}

// Flat list of everyone, kept as reactive state (the lookup maps above are plain, non-reactive helpers).
const people = ref([]);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/employees/org-chart');
        byId.clear();
        parentOf.clear();
        index(data.data ?? []);
        roots.value = data.data ?? [];
        people.value = [...byId.values()];
        expandToDepth(1);
    } finally {
        loading.value = false;
    }
    // Once rendered: fit the width but never start smaller than a readable 75%, centred on the top of the chart.
    await nextTick();
    requestAnimationFrame(initialView);
}

function initialView() {
    if (!canvas.value || !chart.value) return;
    const naturalWidth = chart.value.scrollWidth / zoom.value;
    zoom.value = clampZoom(Math.max(0.75, Math.min(1, (canvas.value.clientWidth - 48) / naturalWidth)));
    nextTick(() => {
        if (!canvas.value) return;
        canvas.value.scrollTop = 0;
        canvas.value.scrollLeft = (canvas.value.scrollWidth - canvas.value.clientWidth) / 2;
    });
}
onMounted(load);

const stats = computed(() => {
    const all = people.value;
    const managers = all.filter((n) => n.children.length);
    return {
        headcount: all.length,
        managers: managers.length,
        span: managers.length ? (managers.reduce((s, n) => s + n.children.length, 0) / managers.length).toFixed(1) : '0',
        levels: all.length ? Math.max(...all.map((n) => n.depth)) + 1 : 0,
        unassigned: roots.value.length > 1 ? roots.value.length - 1 : 0,
    };
});

// ── Departments & colours ─────────────────────────────────────────────────────────────────────
const PALETTE = ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#0ea5e9', '#8b5cf6', '#14b8a6', '#ec4899', '#84cc16', '#f97316', '#64748b', '#06b6d4'];
const departments = computed(() => {
    const map = new Map();
    for (const n of people.value) {
        const key = n.department_id ?? 'none';
        if (!map.has(key)) map.set(key, { id: n.department_id ?? '', name: n.department ?? 'Unassigned', count: 0 });
        map.get(key).count++;
    }
    return [...map.values()].sort((a, b) => a.name.localeCompare(b.name))
        .map((d, i) => ({ ...d, color: d.id === '' ? '#94a3b8' : PALETTE[i % PALETTE.length] }));
});
const colorFor = (departmentId) => departments.value.find((d) => String(d.id) === String(departmentId ?? ''))?.color ?? '#94a3b8';

// ── Expand / collapse ─────────────────────────────────────────────────────────────────────────
function expandToDepth(depth) {
    const set = new Set();
    for (const n of byId.values()) if (n.children.length && n.depth < depth) set.add(n.id);
    expanded.value = set;
}
function toggle(id) {
    const set = new Set(expanded.value);
    set.has(id) ? set.delete(id) : set.add(id);
    expanded.value = set;
}
const expandAll = () => expandToDepth(Infinity);
const collapseAll = () => expandToDepth(1);

function revealPath(id) {
    const set = new Set(expanded.value);
    let p = parentOf.get(id);
    while (p != null) {
        set.add(p);
        p = parentOf.get(p);
    }
    expanded.value = set;
}

// ── Search ────────────────────────────────────────────────────────────────────────────────────
const results = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (q.length < 2) return [];
    return people.value.filter((n) => `${n.name} ${n.position ?? ''} ${n.department ?? ''} ${n.employee_number ?? ''}`.toLowerCase().includes(q)).slice(0, 8);
});
const matchIds = computed(() => (query.value.trim().length >= 2 && !searchOpen.value ? new Set(results.value.map((r) => r.id)) : new Set()));

onClickOutside(searchBox, () => (searchOpen.value = false));

async function focusPerson(person, select = true) {
    searchOpen.value = false;
    query.value = '';
    revealPath(person.id);
    highlightId.value = person.id;
    if (select) selected.value = person;
    if (zoom.value < 0.8) zoom.value = 1; // make the person readable before scrolling to them
    await nextTick();
    document.getElementById(`org-node-${person.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
    setTimeout(() => (highlightId.value = null), 2400);
}

// ── Zoom & pan ────────────────────────────────────────────────────────────────────────────────
const clampZoom = (z) => Math.min(1.6, Math.max(0.3, Math.round(z * 100) / 100));
const zoomBy = (delta) => (zoom.value = clampZoom(zoom.value + delta));

function fit() {
    if (!canvas.value || !chart.value) return;
    const prev = zoom.value;
    const naturalWidth = chart.value.scrollWidth / prev;
    const naturalHeight = chart.value.scrollHeight / prev;
    const z = Math.min((canvas.value.clientWidth - 48) / naturalWidth, (canvas.value.clientHeight - 48) / naturalHeight, 1);
    zoom.value = clampZoom(z);
    nextTick(() => canvas.value && (canvas.value.scrollLeft = (canvas.value.scrollWidth - canvas.value.clientWidth) / 2));
}

useEventListener(canvas, 'wheel', (e) => {
    if (!e.ctrlKey && !e.metaKey) return;
    e.preventDefault();
    zoomBy(e.deltaY < 0 ? 0.1 : -0.1);
}, { passive: false });

const drag = ref(null);
function startPan(e) {
    if (e.button !== 0 || e.target.closest('.org-card, button, input, select, a')) return;
    drag.value = { x: e.clientX, y: e.clientY, left: canvas.value.scrollLeft, top: canvas.value.scrollTop };
}
useEventListener(window, 'mousemove', (e) => {
    if (!drag.value) return;
    canvas.value.scrollLeft = drag.value.left - (e.clientX - drag.value.x);
    canvas.value.scrollTop = drag.value.top - (e.clientY - drag.value.y);
});
useEventListener(window, 'mouseup', () => (drag.value = null));

watch(departmentFilter, async (id) => {
    if (id === '') return;
    // Reveal everyone in the department so the filter is meaningful.
    for (const n of people.value) if (String(n.department_id) === String(id)) revealPath(n.id);
    await nextTick();
    const first = people.value.find((n) => String(n.department_id) === String(id));
    if (first) document.getElementById(`org-node-${first.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
});

// ── Details drawer ────────────────────────────────────────────────────────────────────────────
const manager = computed(() => (selected.value ? byId.get(parentOf.get(selected.value.id)) : null));
const companyName = computed(() => auth.user?.company?.name ?? 'Organisation');

function printChart() {
    expandAll();
    nextTick(() => {
        const prev = zoom.value;
        fit();
        setTimeout(() => {
            window.print();
            zoom.value = prev;
        }, 300);
    });
}
</script>

<template>
    <div class="org-page">
        <PageHeader title="Organisation chart" :subtitle="`Reporting lines for ${companyName}. Click a person for details; Ctrl + scroll to zoom, drag to move.`" class="no-print" />

        <!-- Stats -->
        <div class="no-print mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-for="s in [
                { label: 'People', value: stats.headcount, icon: Users, tone: 'text-brand-600 bg-brand-50 dark:bg-brand-500/15' },
                { label: 'Managers', value: stats.managers, icon: Network, tone: 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/15' },
                { label: 'Avg. span of control', value: stats.span, icon: GitBranch, tone: 'text-amber-600 bg-amber-50 dark:bg-amber-500/15' },
                { label: 'Reporting levels', value: stats.levels, icon: Building2, tone: 'text-sky-600 bg-sky-50 dark:bg-sky-500/15' },
            ]" :key="s.label" class="card flex items-center gap-3 p-3.5">
                <span class="rounded-lg p-2" :class="s.tone"><component :is="s.icon" class="size-4" /></span>
                <div>
                    <p class="text-lg font-bold leading-tight text-slate-900 dark:text-white">{{ loading ? '–' : s.value }}</p>
                    <p class="text-xs text-slate-500">{{ s.label }}</p>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="no-print card mb-4 flex flex-wrap items-center gap-2 p-3">
            <div ref="searchBox" class="relative w-full sm:w-72">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="query"
                    class="input !py-2 !pl-9"
                    placeholder="Find a person, role or department"
                    aria-label="Search the organisation chart"
                    @focus="searchOpen = true"
                    @input="searchOpen = true"
                    @keydown.enter.prevent="results[0] && focusPerson(results[0])"
                    @keydown.esc="searchOpen = false"
                />
                <div v-if="searchOpen && query.trim().length >= 2" class="card absolute z-30 mt-1 w-full overflow-hidden shadow-lg">
                    <p v-if="!results.length" class="px-3 py-3 text-sm text-slate-500">No one matches “{{ query }}”.</p>
                    <button v-for="r in results" :key="r.id" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800" @click="focusPerson(r)">
                        <Avatar :name="r.name" :src="r.photo_url" size="xs" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{ r.name }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ r.position }} · {{ r.department ?? 'Unassigned' }}</span>
                        </span>
                    </button>
                </div>
            </div>

            <select v-model="departmentFilter" class="input !w-full !py-2 sm:!w-56" aria-label="Highlight department">
                <option value="">All departments</option>
                <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }} ({{ d.count }})</option>
            </select>

            <div class="ml-auto flex flex-wrap items-center gap-1">
                <button class="btn-ghost !px-2.5 !py-2" title="Expand all" aria-label="Expand all" @click="expandAll"><ChevronsDown class="size-4" /></button>
                <button class="btn-ghost !px-2.5 !py-2" title="Collapse to top levels" aria-label="Collapse all" @click="collapseAll"><ChevronsUp class="size-4" /></button>
                <span class="mx-1 h-6 w-px bg-slate-200 dark:bg-slate-700" />
                <button class="btn-ghost !px-2.5 !py-2" title="Zoom out" aria-label="Zoom out" @click="zoomBy(-0.1)"><ZoomOut class="size-4" /></button>
                <button class="w-14 rounded-lg py-1.5 text-center text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="Reset to 100%" @click="zoom = 1">{{ Math.round(zoom * 100) }}%</button>
                <button class="btn-ghost !px-2.5 !py-2" title="Zoom in" aria-label="Zoom in" @click="zoomBy(0.1)"><ZoomIn class="size-4" /></button>
                <button class="btn-ghost !px-2.5 !py-2" title="Fit to screen" aria-label="Fit to screen" @click="fit"><Maximize2 class="size-4" /></button>
                <button class="btn-ghost !px-2.5 !py-2" title="Reset view" aria-label="Reset view" @click="departmentFilter = ''; collapseAll(); $nextTick(fit)"><RotateCcw class="size-4" /></button>
                <span class="mx-1 h-6 w-px bg-slate-200 dark:bg-slate-700" />
                <button class="btn-secondary !py-2" @click="printChart"><Printer class="size-4" /> Print / PDF</button>
            </div>
        </div>

        <!-- Legend -->
        <div v-if="departments.length" class="no-print mb-3 flex flex-wrap gap-x-4 gap-y-1.5 px-1">
            <button
                v-for="d in departments"
                :key="d.id"
                class="inline-flex items-center gap-1.5 rounded-full px-1.5 py-0.5 text-xs transition"
                :class="String(departmentFilter) === String(d.id) ? 'bg-slate-200 font-semibold text-slate-900 dark:bg-slate-700 dark:text-white' : 'text-slate-600 hover:text-slate-900 dark:text-slate-400'"
                @click="departmentFilter = String(departmentFilter) === String(d.id) ? '' : d.id"
            >
                <span class="size-2.5 rounded-full" :style="{ background: d.color }" /> {{ d.name }}
            </button>
        </div>

        <!-- Canvas -->
        <div
            ref="canvas"
            class="org-canvas card relative h-[calc(100vh-22rem)] min-h-[28rem] overflow-auto"
            :class="drag ? 'cursor-grabbing select-none' : 'cursor-grab'"
            @mousedown="startPan"
        >
            <div v-if="loading" class="flex h-full flex-col items-center justify-center gap-6">
                <div class="h-24 w-56 animate-pulse rounded-xl bg-slate-100 dark:bg-slate-800" />
                <div class="flex gap-6"><div v-for="i in 3" :key="i" class="h-24 w-56 animate-pulse rounded-xl bg-slate-100 dark:bg-slate-800" /></div>
            </div>
            <EmptyState v-else-if="!roots.length" :icon="Network" title="No reporting lines yet" description="Assign primary supervisors to employees to build the organisation chart." class="h-full" />

            <div v-else ref="chart" class="org-tree inline-block min-w-full p-8" :style="{ zoom }">
                <ul>
                    <li>
                        <!-- Company root -->
                        <div class="org-card mx-auto flex w-64 items-center gap-3 rounded-xl border border-slate-900 bg-slate-900 px-4 py-3.5 text-white shadow-md dark:border-slate-700 dark:bg-slate-800">
                            <img v-if="auth.user?.company?.logo_url" :src="auth.user.company.logo_url" alt="" class="size-10 rounded-lg bg-white object-contain p-1" />
                            <span v-else class="flex size-10 items-center justify-center rounded-lg bg-white/15"><Building2 class="size-5" /></span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold">{{ companyName }}</p>
                                <p class="text-xs text-white/70">{{ stats.headcount }} people · {{ departments.length }} departments</p>
                            </div>
                        </div>
                        <ul>
                            <OrgChartNode
                                v-for="node in roots"
                                :key="node.id"
                                :node="node"
                                :expanded="expanded"
                                :highlight-id="highlightId"
                                :selected-id="selected?.id"
                                :match-ids="matchIds"
                                :department-filter="departmentFilter"
                                :color-for="colorFor"
                                @toggle="toggle"
                                @select="selected = $event"
                            />
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
        <p v-if="stats.unassigned" class="no-print mt-2 text-xs text-slate-500">
            {{ stats.unassigned + 1 }} people have no supervisor and appear directly under the company. Assign supervisors in the employee directory to complete the chart.
        </p>

        <!-- Details drawer -->
        <Transition name="drawer">
            <aside v-if="selected" class="no-print fixed inset-y-0 right-0 z-40 flex w-full max-w-sm flex-col border-l border-slate-200 bg-white shadow-2xl dark:border-slate-800 dark:bg-slate-900" aria-label="Employee details">
                <div class="relative h-24 shrink-0" :style="{ background: `linear-gradient(135deg, ${colorFor(selected.department_id)}, ${colorFor(selected.department_id)}99)` }">
                    <button class="absolute right-3 top-3 rounded-lg bg-black/20 p-1.5 text-white hover:bg-black/30" aria-label="Close" @click="selected = null"><X class="size-4" /></button>
                </div>
                <div class="relative z-10 -mt-10 px-5">
                    <Avatar :name="selected.name" :src="selected.photo_url" size="xl" class="ring-4 ring-white dark:ring-slate-900" />
                    <h2 class="mt-3 text-lg font-bold text-slate-900 dark:text-white">{{ selected.name }}</h2>
                    <p class="text-sm text-slate-500">{{ selected.position ?? 'No position' }}</p>
                    <p class="mt-1 inline-flex items-center gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-400">
                        <span class="size-2 rounded-full" :style="{ background: colorFor(selected.department_id) }" /> {{ selected.department ?? 'Unassigned' }}
                    </p>
                </div>

                <div class="scrollbar-thin flex-1 space-y-5 overflow-y-auto px-5 py-5 text-sm">
                    <div class="space-y-2.5">
                        <p v-if="selected.email" class="flex items-center gap-2.5"><Mail class="size-4 text-slate-400" /><a :href="`mailto:${selected.email}`" class="truncate text-brand-600 hover:underline dark:text-brand-400">{{ selected.email }}</a></p>
                        <p v-if="selected.phone" class="flex items-center gap-2.5"><Phone class="size-4 text-slate-400" /><a :href="`tel:${selected.phone}`" class="hover:underline">{{ selected.phone }}</a></p>
                        <p v-if="selected.branch" class="flex items-center gap-2.5"><MapPin class="size-4 text-slate-400" />{{ selected.branch }}</p>
                        <p v-if="selected.date_of_joining" class="flex items-center gap-2.5"><CalendarDays class="size-4 text-slate-400" />Joined {{ formatDate(selected.date_of_joining) }}</p>
                        <p v-if="selected.employee_number" class="flex items-center gap-2.5"><UserRound class="size-4 text-slate-400" />{{ selected.employee_number }}</p>
                    </div>

                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Reports to</p>
                        <button v-if="manager" class="flex w-full items-center gap-3 rounded-xl border border-slate-100 p-2.5 text-left hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800" @click="focusPerson(manager)">
                            <Avatar :name="manager.name" :src="manager.photo_url" size="sm" />
                            <span class="min-w-0"><span class="block truncate font-medium">{{ manager.name }}</span><span class="block truncate text-xs text-slate-500">{{ manager.position }}</span></span>
                        </button>
                        <p v-else class="text-slate-500">No supervisor assigned</p>
                    </div>

                    <div v-if="selected.children.length">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Direct reports ({{ selected.children.length }})<span v-if="selected.total > selected.children.length" class="normal-case"> · {{ selected.total }} in total</span>
                        </p>
                        <div class="space-y-1.5">
                            <button v-for="c in selected.children" :key="c.id" class="flex w-full items-center gap-3 rounded-xl border border-slate-100 p-2.5 text-left hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800" @click="focusPerson(c)">
                                <Avatar :name="c.name" :src="c.photo_url" size="sm" />
                                <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ c.name }}</span><span class="block truncate text-xs text-slate-500">{{ c.position }}</span></span>
                                <span v-if="c.children.length" class="text-xs text-slate-400">{{ c.children.length }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="auth.can('employees.view')" class="border-t border-slate-100 p-4 dark:border-slate-800">
                    <button class="btn-primary w-full" @click="router.push(`/employees/${selected.id}`)"><ExternalLink class="size-4" /> Open full profile</button>
                </div>
            </aside>
        </Transition>
    </div>
</template>

<style scoped>
/* Tree connectors: classic CSS org-chart lines between <ul>/<li> levels. */
.org-tree {
    --line: rgb(203 213 225);
    --gap: 28px;
    text-align: center;
}

.org-tree :deep(ul) {
    position: relative;
    display: flex;
    justify-content: center;
    padding-top: var(--gap);
    margin: 0;
}
.org-tree > ul { padding-top: 0; }

.org-tree :deep(li) {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: var(--gap) 10px 0;
    list-style: none;
}
.org-tree > ul > li { padding-top: 0; }

/* Horizontal bar + vertical drop to each child */
.org-tree :deep(li)::before,
.org-tree :deep(li)::after {
    content: '';
    position: absolute;
    top: 0;
    right: 50%;
    width: 50%;
    height: var(--gap);
    border-top: 2px solid var(--line);
}
.org-tree :deep(li)::after {
    right: auto;
    left: 50%;
    border-left: 2px solid var(--line);
}
.org-tree :deep(li:only-child)::before { display: none; }
.org-tree :deep(li:only-child)::after { border-top: 0; border-radius: 0; }
.org-tree :deep(li:first-child)::before,
.org-tree :deep(li:last-child)::after { border: 0 none; }
.org-tree :deep(li:last-child)::before {
    border-right: 2px solid var(--line);
    border-radius: 0 10px 0 0;
}
.org-tree :deep(li:first-child)::after { border-radius: 10px 0 0 0; }
.org-tree > ul > li::before,
.org-tree > ul > li::after { display: none; }

/* Vertical line down from a parent card to its children's bar */
.org-tree :deep(ul ul)::before {
    content: '';
    position: absolute;
    top: 0;
    left: 50%;
    height: var(--gap);
    border-left: 2px solid var(--line);
}

.org-tree :deep(.org-pulse) { animation: org-pulse 0.8s ease-in-out 3; }
@keyframes org-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgb(99 102 241 / 0.5); }
    50% { box-shadow: 0 0 0 10px rgb(99 102 241 / 0); }
}

.drawer-enter-active, .drawer-leave-active { transition: transform 0.25s ease, opacity 0.25s ease; }
.drawer-enter-from, .drawer-leave-to { transform: translateX(100%); opacity: 0; }

@media print {
    .org-canvas { height: auto !important; min-height: 0 !important; overflow: visible !important; border: 0 !important; box-shadow: none !important; }
}
</style>

<style>
/* Softer connector lines in dark mode (kept unscoped so the .dark ancestor selector applies). */
.dark .org-tree { --line: rgb(51 65 85) !important; }

@media print {
    .org-tree { --line: rgb(148 163 184) !important; }
    @page { size: landscape; margin: 10mm; }
    aside, header, .no-print { display: none !important; }
    main { overflow: visible !important; padding: 0 !important; }
    body { background: #fff !important; }
}
</style>
