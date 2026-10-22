<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import { watchDebounced } from '@vueuse/core';
import { Search, CornerDownLeft, FileText, Megaphone, Sun, LogOut } from 'lucide-vue-next';
import api from '@/lib/api';
import { searchablePages } from '@/navigation';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import Avatar from '@/components/ui/Avatar.vue';
import Spinner from '@/components/ui/Spinner.vue';

const ui = useUiStore();
const auth = useAuthStore();
const router = useRouter();

const query = ref('');
const records = ref([]);
const loading = ref(false);
const active = ref(0);
const input = ref(null);

const actions = [
    { label: 'Toggle theme', icon: Sun, run: () => ui.cycleTheme() },
    { label: 'Sign out', icon: LogOut, run: async () => { await auth.logout(); router.replace('/login'); } },
];

const results = computed(() => {
    const q = query.value.toLowerCase().trim();
    const pages = searchablePages(auth)
        .filter((p) => !q || p.label.toLowerCase().includes(q))
        .map((p) => ({ group: 'Pages', title: p.label, icon: p.icon, to: p.to }));
    const acts = actions.filter((a) => !q || a.label.toLowerCase().includes(q)).map((a) => ({ group: 'Actions', title: a.label, icon: a.icon, run: a.run }));
    const recs = records.value.map((r) => ({
        group: r.type === 'employee' ? 'People' : 'Announcements',
        title: r.title,
        subtitle: r.subtitle,
        photo: r.photo_url,
        avatar: r.type === 'employee',
        icon: r.type === 'announcement' ? Megaphone : FileText,
        to: r.url,
    }));
    return [...recs, ...pages.slice(0, q ? 8 : 6), ...acts];
});

const grouped = computed(() => {
    const groups = {};
    results.value.forEach((r, i) => (groups[r.group] ??= []).push({ ...r, index: i }));
    return groups;
});

watchDebounced(query, async (q) => {
    if (q.trim().length < 2) {
        records.value = [];
        return;
    }
    loading.value = true;
    try {
        records.value = (await api.get('/search', { params: { q } })).data.results;
    } finally {
        loading.value = false;
    }
}, { debounce: 250 });

watch(() => ui.commandPaletteOpen, async (open) => {
    if (open) {
        query.value = '';
        records.value = [];
        active.value = 0;
        await nextTick();
        input.value?.focus();
    }
});
watch(results, () => (active.value = 0));

function choose(item) {
    ui.commandPaletteOpen = false;
    if (item.run) item.run();
    else if (item.to) router.push(item.to);
}

function onKey(e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); active.value = (active.value + 1) % results.value.length; }
    if (e.key === 'ArrowUp') { e.preventDefault(); active.value = (active.value - 1 + results.value.length) % results.value.length; }
    if (e.key === 'Enter' && results.value[active.value]) { e.preventDefault(); choose(results.value[active.value]); }
    if (e.key === 'Escape') ui.commandPaletteOpen = false;
}
</script>

<template>
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="ui.commandPaletteOpen" class="fixed inset-0 z-50 flex items-start justify-center p-4 pt-[12vh]">
                <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="ui.commandPaletteOpen = false" />
                <div class="card relative w-full max-w-xl overflow-hidden shadow-2xl" @keydown="onKey">
                    <div class="flex items-center gap-3 border-b border-slate-100 px-4 dark:border-slate-800">
                        <Search class="size-5 text-slate-400" />
                        <input ref="input" v-model="query" class="h-14 flex-1 bg-transparent text-sm outline-none placeholder:text-slate-400" placeholder="Search people, pages and actions…" />
                        <Spinner v-if="loading" class="text-slate-400" />
                        <kbd class="rounded-md border border-slate-200 px-1.5 py-0.5 text-[11px] text-slate-400 dark:border-slate-700">Esc</kbd>
                    </div>
                    <div class="scrollbar-thin max-h-[60vh] overflow-y-auto p-2">
                        <p v-if="!results.length" class="px-3 py-10 text-center text-sm text-slate-500">No results for “{{ query }}”.</p>
                        <div v-for="(items, group) in grouped" :key="group" class="mb-2">
                            <p class="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ group }}</p>
                            <button
                                v-for="item in items"
                                :key="item.index"
                                class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm"
                                :class="active === item.index ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' : 'text-slate-700 dark:text-slate-300'"
                                @mouseenter="active = item.index"
                                @click="choose(item)"
                            >
                                <Avatar v-if="item.avatar" :name="item.title" :src="item.photo" size="xs" />
                                <component :is="item.icon" v-else class="size-4 text-slate-400" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium">{{ item.title }}</span>
                                    <span v-if="item.subtitle" class="block truncate text-xs text-slate-500">{{ item.subtitle }}</span>
                                </span>
                                <CornerDownLeft v-if="active === item.index" class="size-4 opacity-60" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
