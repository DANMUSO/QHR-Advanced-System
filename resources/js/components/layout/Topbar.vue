<script setup>
import { ref } from 'vue';
import { Menu, Search, Sun, Moon, Monitor, Building2 } from 'lucide-vue-next';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import NotificationBell from './NotificationBell.vue';

const auth = useAuthStore();
const ui = useUiStore();

const themeIcon = { light: Sun, dark: Moon, system: Monitor };
const isMac = navigator.platform.toUpperCase().includes('MAC');

function changeCompany(e) {
    ui.setCompanyScope(e.target.value);
    window.location.reload();
}
</script>

<template>
    <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur-md sm:px-6 dark:border-slate-800 dark:bg-slate-900/90">
        <button class="btn-ghost !p-2 lg:hidden" @click="ui.sidebarOpen = true" aria-label="Open menu">
            <Menu class="size-5" />
        </button>

        <button
            class="flex h-9 w-full max-w-xs items-center gap-2.5 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm text-slate-400 transition hover:border-slate-300 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-800/60 dark:hover:bg-slate-800"
            @click="ui.commandPaletteOpen = true"
        >
            <Search class="size-4 shrink-0" />
            <span class="flex-1 text-left">Search…</span>
            <kbd class="hidden rounded border border-slate-200 bg-white px-1.5 py-0.5 font-sans text-[11px] text-slate-400 sm:inline dark:border-slate-600 dark:bg-slate-700">
                {{ isMac ? '⌘K' : 'Ctrl K' }}
            </kbd>
        </button>

        <div class="ml-auto flex items-center gap-1">
            <label v-if="auth.isSuperAdmin" class="hidden items-center gap-2 md:flex">
                <Building2 class="size-4 text-slate-400" />
                <select :value="ui.companyScope" class="input !w-44 !py-1.5 text-sm" @change="changeCompany">
                    <option value="">All companies</option>
                    <option v-for="c in auth.user.companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                </select>
            </label>

            <button class="btn-ghost !p-2" :title="`Theme: ${ui.theme}`" @click="ui.cycleTheme()">
                <component :is="themeIcon[ui.theme]" class="size-4.5" />
            </button>

            <NotificationBell />
        </div>
    </header>
</template>
