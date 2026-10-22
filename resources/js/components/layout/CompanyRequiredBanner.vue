<script setup>
import { watch } from 'vue';
import { useRoute } from 'vue-router';
import { Building2 } from 'lucide-vue-next';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';

/** Shown to super admins on pages that work on one company while "All companies" is selected. */
const auth = useAuthStore();
const ui = useUiStore();
const route = useRoute();

watch(() => route.path, () => (ui.companyRequired = false));

function choose(e) {
    if (!e.target.value) return;
    ui.setCompanyScope(e.target.value);
    window.location.reload();
}
</script>

<template>
    <div
        v-if="ui.companyRequired && auth.isSuperAdmin && !ui.companyScope"
        class="mb-6 flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
        role="status"
    >
        <Building2 class="size-5 shrink-0" />
        <span class="flex-1">This page works on one company at a time. Choose the company you want to manage.</span>
        <select class="input !w-56 !py-1.5" aria-label="Choose company" @change="choose">
            <option value="">Select a company…</option>
            <option v-for="c in auth.user?.companies ?? []" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
        </select>
    </div>
</template>
