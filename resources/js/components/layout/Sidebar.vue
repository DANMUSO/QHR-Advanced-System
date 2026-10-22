<script setup>
import { computed } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import { ChevronLeft, X, LogOut, ShieldCheck } from 'lucide-vue-next';
import { navigation } from '@/navigation';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import Avatar from '@/components/ui/Avatar.vue';
import { useRouter } from 'vue-router';

const auth = useAuthStore();
const ui = useUiStore();
const route = useRoute();
const router = useRouter();

const sections = computed(() => {
    if (!auth.loaded) return [];
    return navigation
        .map((s) => ({ ...s, items: s.items.filter((i) => !i.searchOnly && (!i.can || auth.can(i.can)) && (!i.hasEmployee || !!auth.user?.employee)) }))
        .filter((s) => s.items.length);
});

// Highlight only the most specific matching item, so "/payroll" isn't lit up on "/payroll/setup".
const activeTo = computed(() => {
    const path = route.path;
    const matches = sections.value.flatMap((s) => s.items)
        .filter((i) => (i.exact ? path === i.to : path === i.to || path.startsWith(i.to.endsWith('/') ? i.to : `${i.to}/`)))
        .map((i) => i.to);
    return matches.sort((a, b) => b.length - a.length)[0] ?? null;
});

function isActive(item) {
    return item.to === activeTo.value;
}

async function logout() {
    await auth.logout();
    router.replace('/login');
}
</script>

<template>
    <!-- Mobile backdrop -->
    <Transition name="fade">
        <div v-if="ui.sidebarOpen" class="fixed inset-0 z-30 bg-slate-900/60 backdrop-blur-sm lg:hidden" @click="ui.sidebarOpen = false" />
    </Transition>

    <aside
        class="fixed inset-y-0 left-0 z-40 flex flex-col bg-slate-950 transition-all duration-200 lg:static lg:translate-x-0"
        :class="[ui.sidebarOpen ? 'translate-x-0' : '-translate-x-full', ui.sidebarCollapsed ? 'lg:w-[68px]' : 'w-[240px]']"
    >
        <!-- Logo -->
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/[0.06] px-4">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-sm font-bold text-white shadow-lg shadow-brand-600/30">
                HR
            </div>
            <div v-if="!ui.sidebarCollapsed" class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-white">HR Advanced</p>
                <p class="truncate text-xs text-slate-400">{{ auth.user?.company?.name ?? 'All companies' }}</p>
            </div>
            <button class="ml-auto rounded-lg p-1 text-slate-500 hover:text-slate-300 lg:hidden" @click="ui.sidebarOpen = false">
                <X class="size-5" />
            </button>
        </div>

        <!-- Nav -->
        <nav class="scrollbar-thin flex-1 overflow-y-auto px-3 py-4 space-y-6">
            <div v-for="section in sections" :key="section.section ?? 'main'">
                <p
                    v-if="section.section && !ui.sidebarCollapsed"
                    class="mb-1 px-2 text-[10px] font-semibold uppercase tracking-widest text-slate-500"
                >
                    {{ section.section }}
                </p>
                <div v-else-if="section.section && ui.sidebarCollapsed" class="mb-1 border-t border-white/[0.06]" />

                <ul class="space-y-0.5">
                    <li v-for="item in section.items" :key="item.to">
                        <RouterLink
                            :to="item.to"
                            class="group relative flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm font-medium transition-all duration-150"
                            :class="isActive(item)
                                ? 'bg-brand-600/20 text-brand-300'
                                : 'text-slate-400 hover:bg-white/[0.05] hover:text-slate-100'"
                            :title="ui.sidebarCollapsed ? item.label : undefined"
                            @click="ui.sidebarOpen = false"
                        >
                            <span
                                v-if="isActive(item)"
                                class="absolute left-0 top-1/2 h-5 w-0.5 -translate-y-1/2 rounded-full bg-brand-400"
                            />
                            <component
                                :is="item.icon"
                                class="size-[18px] shrink-0 transition-colors"
                                :class="isActive(item) ? 'text-brand-400' : 'text-slate-500 group-hover:text-slate-300'"
                            />
                            <span v-if="!ui.sidebarCollapsed" class="flex-1 truncate">{{ item.label }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- User card -->
        <div class="border-t border-white/[0.06] p-3">
            <div v-if="!ui.sidebarCollapsed" class="flex items-center gap-3 rounded-lg px-2 py-2">
                <Avatar :name="auth.user?.name" :src="auth.user?.employee?.photo_url" size="sm" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-slate-200">{{ auth.user?.name }}</p>
                    <p class="truncate text-xs text-slate-500">{{ auth.user?.employee?.position ?? auth.user?.roles?.[0] }}</p>
                </div>
                <div class="flex gap-1">
                    <RouterLink to="/account/security" class="rounded-md p-1.5 text-slate-500 hover:bg-white/[0.06] hover:text-slate-300" title="Account security">
                        <ShieldCheck class="size-4" />
                    </RouterLink>
                    <button class="rounded-md p-1.5 text-slate-500 hover:bg-white/[0.06] hover:text-rose-400" title="Sign out" @click="logout">
                        <LogOut class="size-4" />
                    </button>
                </div>
            </div>
            <div v-else class="flex flex-col items-center gap-2">
                <Avatar :name="auth.user?.name" :src="auth.user?.employee?.photo_url" size="sm" />
                <button class="rounded-md p-1.5 text-slate-500 hover:bg-white/[0.06] hover:text-rose-400" title="Sign out" @click="logout">
                    <LogOut class="size-4" />
                </button>
            </div>
        </div>

        <!-- Collapse toggle -->
        <button
            class="hidden h-10 items-center justify-center border-t border-white/[0.06] text-slate-600 hover:text-slate-400 lg:flex"
            @click="ui.toggleCollapsed()"
            :title="ui.sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        >
            <ChevronLeft class="size-4 transition-transform duration-200" :class="ui.sidebarCollapsed && 'rotate-180'" />
        </button>
    </aside>
</template>
