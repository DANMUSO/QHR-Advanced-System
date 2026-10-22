<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { Plus, Pencil, Trash2, ArrowUp, ArrowDown, X, ToggleLeft, ToggleRight, Info } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useCompanyContext } from '../../company';

/** Approval chains per business process (one active workflow per trigger). */
const ui = useUiStore();
const { cfg } = useCompanyContext();

const rows = ref([]);
const options = ref({ triggers: {}, step_types: {}, roles: [], honoured_triggers: [] });
const loading = ref(false);

const columns = [
    { key: 'name', label: 'Workflow', sortable: true },
    { key: 'trigger_label', label: 'Trigger' },
    { key: 'steps', label: 'Approval chain' },
    { key: 'is_active', label: 'Status' },
];

const honoured = (t) => options.value.honoured_triggers.includes(t);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/organization/workflows', cfg());
        rows.value = data.data ?? [];
        if (data.options) options.value = { honoured_triggers: [], ...data.options };
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

// ---- editor ------------------------------------------------------------------------------
const editor = reactive({ open: false, id: null, form: {}, errors: {}, saving: false });
const activeForTrigger = computed(() => rows.value.find((r) => r.is_active && r.trigger === editor.form.trigger && r.id !== editor.id));

function open(row = null) {
    Object.assign(editor, {
        open: true, id: row?.id ?? null, errors: {}, saving: false,
        form: row
            ? { name: row.name, trigger: row.trigger, description: row.description ?? '', is_active: row.is_active, steps: (row.steps ?? []).map((s) => ({ ...s })) }
            : { name: '', trigger: 'leave_request', description: '', is_active: true, steps: [{ type: 'supervisor', role: null, label: '' }] },
    });
}

function addStep() {
    if (editor.form.steps.length >= 6) return;
    editor.form.steps.push({ type: 'hod', role: null, label: '' });
}
function removeStep(i) { editor.form.steps.splice(i, 1); }
function move(i, d) {
    const s = editor.form.steps;
    const j = i + d;
    if (j < 0 || j >= s.length) return;
    [s[i], s[j]] = [s[j], s[i]];
}
function stepError(i, field) { return editor.errors[`steps.${i}.${field}`]; }

async function save() {
    editor.saving = true;
    editor.errors = {};
    const payload = { ...editor.form, steps: editor.form.steps.map((s) => ({ type: s.type, role: s.type === 'role' ? s.role : null, label: s.label || null })) };
    try {
        const { data } = editor.id
            ? await api.put(`/organization/workflows/${editor.id}`, payload, cfg())
            : await api.post('/organization/workflows', payload, cfg());
        ui.toast(data.message);
        editor.open = false;
        load();
    } catch (e) {
        editor.errors = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        editor.saving = false;
    }
}

async function toggle(row) {
    try {
        const { data } = await api.patch(`/organization/workflows/${row.id}/toggle`, {}, cfg());
        ui.toast(data.message);
        load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    }
}

const del = reactive({ row: null, loading: false });
async function remove() {
    del.loading = true;
    try {
        const { data } = await api.delete(`/organization/workflows/${del.row.id}`, cfg());
        ui.toast(data.message);
        del.row = null;
        load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        del.loading = false;
    }
}

function stepText(s) {
    return s.label || (s.type === 'role' ? s.role : options.value.step_types[s.type]) || s.type;
}

defineExpose({ open });
onMounted(load);
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex max-w-3xl gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200">
                <Info class="mt-0.5 size-4 shrink-0" />
                <p>
                    A workflow replaces a module's built-in approval chain for its trigger. Only one workflow per trigger can be active.
                    Currently used by: <strong>{{ options.honoured_triggers.map((t) => options.triggers[t] ?? t).join(', ') || '—' }}</strong>.
                    Other triggers can be prepared now but are not yet used by their module.
                </p>
            </div>
            <button class="btn-primary" @click="open()"><Plus class="size-4" /> New workflow</button>
        </div>

        <DataTable :columns="columns" :rows="rows" :loading="loading" empty-title="No workflows yet"
            empty-description="Modules use their built-in approval chain until you add one.">
            <template #cell-name="{ row, value }">
                <span class="font-medium text-slate-900 dark:text-white">{{ value }}</span>
                <p v-if="row.description" class="max-w-xs truncate text-xs text-slate-500">{{ row.description }}</p>
            </template>
            <template #cell-trigger_label="{ row, value }">
                <span>{{ value }}</span>
                <Badge v-if="!honoured(row.trigger)" tone="amber" class="ml-2">Not yet used by the module</Badge>
            </template>
            <template #cell-steps="{ row }">
                <div class="flex flex-wrap items-center gap-1 text-xs">
                    <template v-for="(s, i) in row.steps" :key="i">
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ i + 1 }}. {{ stepText(s) }}</span>
                    </template>
                </div>
            </template>
            <template #cell-is_active="{ row }">
                <Badge :tone="row.is_active ? 'green' : 'slate'">{{ row.is_active ? 'Active' : 'Inactive' }}</Badge>
            </template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" :title="row.is_active ? 'Deactivate' : 'Activate'" @click="toggle(row)">
                    <ToggleRight v-if="row.is_active" class="size-5 text-emerald-500" /><ToggleLeft v-else class="size-5 text-slate-400" />
                </button>
                <button class="btn-ghost !p-1.5" title="Edit" @click="open(row)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !p-1.5 !text-rose-600" title="Delete" @click="del.row = row"><Trash2 class="size-4" /></button>
            </template>
        </DataTable>

        <Modal :open="editor.open" :title="editor.id ? 'Edit workflow' : 'New workflow'" size="lg" @close="editor.open = false">
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Name <span class="text-rose-500">*</span></label>
                        <input v-model="editor.form.name" class="input" placeholder="e.g. Leave approval" />
                        <p v-if="editor.errors.name" class="field-error">{{ editor.errors.name }}</p>
                    </div>
                    <div>
                        <label class="label">Trigger <span class="text-rose-500">*</span></label>
                        <select v-model="editor.form.trigger" class="input">
                            <option v-for="(label, key) in options.triggers" :key="key" :value="key">{{ label }}{{ honoured(key) ? '' : ' (not yet used)' }}</option>
                        </select>
                        <p v-if="editor.errors.trigger" class="field-error">{{ editor.errors.trigger }}</p>
                    </div>
                </div>
                <p v-if="!honoured(editor.form.trigger)" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                    This trigger is not yet used by its module: the workflow is saved but approvals still follow the built-in flow.
                </p>
                <div>
                    <label class="label">Description</label>
                    <textarea v-model="editor.form.description" rows="2" class="input" />
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <label class="label !mb-0">Approval steps (in order)</label>
                        <button type="button" class="btn-secondary !py-1.5 text-xs" :disabled="editor.form.steps.length >= 6" @click="addStep"><Plus class="size-3.5" /> Add step</button>
                    </div>
                    <p v-if="editor.errors.steps" class="field-error mb-2">{{ editor.errors.steps }}</p>
                    <ol class="space-y-2">
                        <li v-for="(s, i) in editor.form.steps" :key="i" class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                            <div class="flex flex-wrap items-start gap-2">
                                <span class="mt-2 flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">{{ i + 1 }}</span>
                                <div class="grid min-w-0 flex-1 gap-2 sm:grid-cols-3">
                                    <div>
                                        <select v-model="s.type" class="input" :aria-label="`Step ${i + 1} approver`">
                                            <option v-for="(label, key) in options.step_types" :key="key" :value="key">{{ label }}</option>
                                        </select>
                                        <p v-if="stepError(i, 'type')" class="field-error">{{ stepError(i, 'type') }}</p>
                                    </div>
                                    <div v-if="s.type === 'role'">
                                        <select v-model="s.role" class="input" :aria-label="`Step ${i + 1} role`">
                                            <option :value="null" disabled>Choose a role…</option>
                                            <option v-for="r in options.roles" :key="r" :value="r">{{ r }}</option>
                                        </select>
                                        <p v-if="stepError(i, 'role')" class="field-error">{{ stepError(i, 'role') }}</p>
                                    </div>
                                    <div :class="s.type === 'role' ? '' : 'sm:col-span-2'">
                                        <input v-model="s.label" class="input" placeholder="Label (optional)" :aria-label="`Step ${i + 1} label`" />
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center">
                                    <button type="button" class="btn-ghost !p-1.5" title="Move up" :disabled="i === 0" @click="move(i, -1)"><ArrowUp class="size-4" /></button>
                                    <button type="button" class="btn-ghost !p-1.5" title="Move down" :disabled="i === editor.form.steps.length - 1" @click="move(i, 1)"><ArrowDown class="size-4" /></button>
                                    <button type="button" class="btn-ghost !p-1.5 !text-rose-600" title="Remove step" :disabled="editor.form.steps.length === 1" @click="removeStep(i)"><X class="size-4" /></button>
                                </div>
                            </div>
                        </li>
                    </ol>
                </div>

                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                    <input v-model="editor.form.is_active" type="checkbox" class="mt-0.5 rounded text-brand-600" />
                    <span>
                        <span class="block text-sm font-medium text-slate-800 dark:text-slate-200">Active</span>
                        <span class="block text-xs text-slate-500">
                            <template v-if="activeForTrigger && editor.form.is_active">Saving deactivates “{{ activeForTrigger.name }}”, the current workflow for this trigger.</template>
                            <template v-else>Only one workflow per trigger can be active.</template>
                        </span>
                    </span>
                </label>
                <button type="submit" class="hidden" />
            </form>
            <template #footer>
                <button class="btn-secondary" @click="editor.open = false">Cancel</button>
                <button class="btn-primary" :disabled="editor.saving" @click="save"><Spinner v-if="editor.saving" /> {{ editor.id ? 'Save changes' : 'Create workflow' }}</button>
            </template>
        </Modal>

        <ConfirmDialog :open="!!del.row" title="Delete workflow" :message="`Delete “${del.row?.name}”? Its trigger falls back to the built-in approval flow.`"
            confirm-label="Delete" danger :loading="del.loading" @confirm="remove" @close="del.row = null" />
    </div>
</template>
