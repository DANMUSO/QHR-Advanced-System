<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { useUiStore } from '@/stores/ui';
import Sidebar from '@/components/layout/Sidebar.vue';
import Topbar from '@/components/layout/Topbar.vue';
import CommandPalette from '@/components/layout/CommandPalette.vue';
import ImpersonationBanner from '@/components/layout/ImpersonationBanner.vue';
import IdleTimeout from '@/components/layout/IdleTimeout.vue';
import CompanyRequiredBanner from '@/components/layout/CompanyRequiredBanner.vue';

const ui = useUiStore();

function onKey(e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        ui.commandPaletteOpen = !ui.commandPaletteOpen;
    }
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <div class="flex h-full flex-col bg-slate-50 dark:bg-slate-950">
        <ImpersonationBanner />
        <div class="flex min-h-0 flex-1">
            <Sidebar />
            <div class="flex min-w-0 flex-1 flex-col">
                <Topbar />
                <main class="scrollbar-thin flex-1 overflow-y-auto px-4 py-6 sm:px-6 lg:px-8">
                    <CompanyRequiredBanner />
                    <RouterView v-slot="{ Component, route }">
                        <Transition name="fade" mode="out-in">
                            <component :is="Component" :key="route.path" />
                        </Transition>
                    </RouterView>
                </main>
            </div>
        </div>
        <CommandPalette />
        <IdleTimeout />
    </div>
</template>
