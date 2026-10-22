<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { Settings, ToggleLeft, ToggleRight, Info } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useCompanyContext } from '../../company';

/** Third-party connections of the current company. Secrets are masked and kept when left unchanged. */
const ui = useUiStore();
const { cfg } = useCompanyContext();

const rows = ref([]);
const loading = ref(false);

/** How the platform uses each integration today. */
const NOTES = {
    sms_africastalking: 'When enabled, SMS alerts to this company\'s staff are sent from this account; otherwise the platform SMS account is used.',
    smtp: 'When enabled, email notifications to this company\'s staff are sent through this server; otherwise the platform mail server is used.',
    biometric_zkteco: 'Reference only. Terminals push punches to the API with their own device token. Terminals are registered in the Attendance module (device tokens), not here.',
    kra_itax: 'Reference details for P10 preparation.',
    mpesa_b2c: 'Reference details. Payments are not disbursed automatically yet.',
    bank_eft: 'Reference details for the salary payment file.',
    slack: 'Reference details. Channel alerts are not sent yet.',
};

const grouped = computed(() => rows.value.reduce((acc, r) => ((acc[r.category] ??= []).push(r), acc), {}));

async function load() {
    loading.value = true;
    try {
        rows.value = (await api.get('/organization/integrations', cfg())).data.data ?? [];
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

async function toggle(row) {
    try {
        const { data } = await api.patch(`/organization/integrations/${row.key}/toggle`, {}, cfg());
        ui.toast(data.message);
        load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    }
}

const editor = reactive({ row: null, form: {}, errors: {}, saving: false });
function open(row) {
    Object.assign(editor, { row, form: { ...row.config }, errors: {}, saving: false });
}
async function save() {
    editor.saving = true;
    editor.errors = {};
    try {
        const { data } = await api.put(`/organization/integrations/${editor.row.key}/config`, editor.form, cfg());
        ui.toast(data.message);
        editor.row = null;
        load();
    } catch (e) {
        editor.errors = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        editor.saving = false;
    }
}

onMounted(load);
</script>

<template>
    <div v-if="loading" class="flex justify-center py-16"><Spinner /></div>
    <div v-else class="space-y-6">
        <section v-for="(items, category) in grouped" :key="category">
            <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ category }}</h3>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="row in items" :key="row.key" class="card flex flex-col p-5">
                    <div class="mb-2 flex items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold text-slate-900 dark:text-white">{{ row.name }}</p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <Badge :tone="row.is_enabled ? 'green' : 'slate'">{{ row.is_enabled ? 'Enabled' : 'Disabled' }}</Badge>
                                <Badge v-if="!row.is_configured" tone="amber">Not configured</Badge>
                            </div>
                        </div>
                        <button class="btn-ghost !p-1.5" :title="row.is_enabled ? 'Disable' : 'Enable'" @click="toggle(row)">
                            <ToggleRight v-if="row.is_enabled" class="size-6 text-emerald-500" /><ToggleLeft v-else class="size-6 text-slate-400" />
                        </button>
                    </div>
                    <p class="text-sm text-slate-500">{{ row.description }}</p>
                    <p v-if="NOTES[row.key]" class="mt-2 flex gap-1.5 text-xs text-slate-500"><Info class="mt-0.5 size-3.5 shrink-0" /> {{ NOTES[row.key] }}</p>
                    <button class="btn-secondary mt-4 w-full" @click="open(row)"><Settings class="size-4" /> Configure</button>
                </div>
            </div>
        </section>

        <Modal :open="!!editor.row" :title="`Configure ${editor.row?.name ?? ''}`" @close="editor.row = null">
            <form class="space-y-4" @submit.prevent="save">
                <div v-for="f in editor.row?.fields ?? []" :key="f.name">
                    <label class="label" :for="`intg-${f.name}`">{{ f.label }}</label>
                    <input :id="`intg-${f.name}`" v-model="editor.form[f.name]" :type="f.secret ? 'password' : f.type === 'number' ? 'number' : 'text'" class="input" autocomplete="off" />
                    <p v-if="editor.errors[f.name]" class="field-error">{{ editor.errors[f.name] }}</p>
                    <p v-else-if="f.secret" class="mt-1 text-xs text-slate-400">Leave the dots unchanged to keep the saved value.</p>
                </div>
                <button type="submit" class="hidden" />
            </form>
            <template #footer>
                <button class="btn-secondary" @click="editor.row = null">Cancel</button>
                <button class="btn-primary" :disabled="editor.saving" @click="save"><Spinner v-if="editor.saving" /> Save</button>
            </template>
        </Modal>
    </div>
</template>
