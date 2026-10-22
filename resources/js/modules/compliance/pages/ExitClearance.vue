<script setup>
import { ref, onMounted } from 'vue';
import { Check } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';

const ui = useUiStore();
const employees = ref([]);
const loading = ref(true);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/compliance/exits');
        employees.value = data.data ?? data;
    } finally {
        loading.value = false;
    }
}

// Assets with items still out need an HR override note (the asset register is the source of truth).
const override = ref({ open: false, emp: null, loading: false, error: '' });

async function clearItem(employeeId, itemKey, overrideNote = null) {
    const emp = employees.value.find((e) => e.id === employeeId);
    if (itemKey === 'assets' && emp?.outstanding_assets?.length && !overrideNote) {
        override.value = { open: true, emp, loading: false, error: '' };
        return;
    }
    try {
        override.value.loading = true;
        await api.post(`/compliance/exits/${employeeId}/clearance/${itemKey}`, overrideNote ? { override_note: overrideNote } : {});
        ui.toast('Clearance item signed off.');
        override.value.open = false;
        await load();
    } catch (e) {
        override.value.error = errorMessage(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        override.value.loading = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Exit & Clearance" />
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else-if="!employees.length" class="card p-10 text-center text-slate-400">No employees in exit process.</div>
        <div v-else class="space-y-4">
            <div v-for="emp in employees" :key="emp.id" class="card p-5">
                <div class="mb-4 flex items-center gap-4">
                    <Avatar :src="emp.photo_url" :name="emp.name" size="md" />
                    <div class="flex-1">
                        <p class="font-semibold text-slate-900 dark:text-white">{{ emp.name }}</p>
                        <p class="text-sm text-slate-500">{{ emp.position }} · Last day: {{ emp.last_working_day }}</p>
                    </div>
                    <Badge :color="emp.clearance_complete ? 'green' : 'yellow'">
                        {{ emp.clearance_complete ? 'Cleared' : 'Pending' }}
                    </Badge>
                </div>
                <div class="space-y-2">
                    <div v-for="item in emp.clearance_items" :key="item.id"
                        class="flex items-center gap-3 rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800">
                        <button @click="!item.cleared && clearItem(emp.id, item.key)">
                            <Check :class="item.cleared ? 'text-green-500' : 'text-slate-300 hover:text-brand-500'" class="size-5" />
                        </button>
                        <span class="flex-1 text-sm" :class="item.cleared ? 'line-through text-slate-400' : ''">{{ item.name }}</span>
                        <span class="text-xs text-slate-400">{{ item.department }}</span>
                        <span v-if="item.cleared_by" class="text-xs text-slate-400">{{ item.cleared_by }}</span>
                        <span v-if="item.override_note" class="text-xs text-amber-600 dark:text-amber-400" :title="item.override_note">Overridden</span>
                    </div>
                    <p v-if="emp.outstanding_assets?.length" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        Assets not yet returned: {{ emp.outstanding_assets.map((a) => a.asset_name + (a.asset_tag ? ` (${a.asset_tag})` : '')).join(', ') }}
                    </p>
                </div>
                <div class="mt-3 flex justify-end">
                    <RouterLink v-if="emp.final_dues_url" :to="emp.final_dues_url" class="btn-secondary text-sm">Prepare final dues</RouterLink>
                </div>
            </div>
        </div>
        <ConfirmDialog :open="override.open" title="Override asset clearance" confirm-label="Sign off with override"
            :message="`${override.emp?.name} still holds: ${(override.emp?.outstanding_assets ?? []).map((a) => a.asset_name).join(', ')}. Explain why the assets item can be cleared anyway.`"
            reason-label="Override note" :loading="override.loading" :error="override.error"
            @close="override.open = false" @confirm="(note) => clearItem(override.emp.id, 'assets', note)" />
    </div>
</template>
