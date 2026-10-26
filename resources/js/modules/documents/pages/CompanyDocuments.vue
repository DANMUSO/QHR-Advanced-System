<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { Plus, Pencil, Trash2, Download, Users, BellRing, Search, FileText } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate } from '@/lib/format';
import { openServerFile } from '@/lib/export';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';

/** HR: publish memos, newsletters and policies, and track acknowledgements. Staff read them in My Portal → Documents. */
const ui = useUiStore();

const tab = ref('memo');
const counts = ref({});
const tabs = computed(() => [
    { key: 'memo', label: 'Memos', count: counts.value.memo },
    { key: 'newsletter', label: 'Newsletters', count: counts.value.newsletter },
    { key: 'policy', label: 'Policies', count: counts.value.policy },
]);
const singular = { memo: 'memo', newsletter: 'newsletter', policy: 'policy' };

const rows = ref([]);
const loading = ref(false);
const search = ref('');
const departments = ref([]);
const branches = ref([]);

const columns = [
    { key: 'title', label: 'Title', sortable: true },
    { key: 'effective_date', label: 'Effective', sortable: true },
    { key: 'audience', label: 'Audience' },
    { key: 'ack_stats', label: 'Acknowledged' },
    { key: 'version', label: 'Version', align: 'right' },
];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/documents/company', { params: { category: tab.value, search: search.value || undefined, per_page: 100 } });
        rows.value = data.data ?? [];
        counts.value = data.category_counts ?? {};
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

async function loadLookups() {
    if (departments.value.length || branches.value.length) return;
    const { data } = await api.get('/lookups', { params: { only: 'departments,branches' } });
    departments.value = data.departments ?? [];
    branches.value = data.branches ?? [];
}

function audienceText(row) {
    if (row.audience === 'all') return 'All staff';
    const list = row.audience === 'branch' ? branches.value : departments.value;
    const names = row.audience_ids.map((id) => list.find((x) => x.id === id)?.name).filter(Boolean);
    return names.length ? names.join(', ') : `${row.audience_ids.length} ${row.audience}(s)`;
}

function switchTab(t) {
    tab.value = t;
    load();
}

// ---- editor ------------------------------------------------------------------------------
const editor = reactive({ open: false, id: null, form: {}, file: null, errors: {}, saving: false });
function open(row = null) {
    loadLookups();
    Object.assign(editor, {
        open: true, id: row?.id ?? null, file: null, errors: {}, saving: false,
        form: row
            ? { category: row.category, title: row.title, description: row.description ?? '', effective_date: row.effective_date ?? '', audience: row.audience, audience_ids: [...row.audience_ids], requires_acknowledgement: row.requires_acknowledgement, referred_by_department_id: row.referred_by_department_id ?? '', reset_acknowledgements: false }
            : { category: tab.value, title: '', description: '', effective_date: new Date().toLocaleDateString('en-CA'), audience: 'all', audience_ids: [], requires_acknowledgement: tab.value === 'policy', referred_by_department_id: '', notify: true },
    });
}

async function save() {
    editor.saving = true;
    editor.errors = {};
    const fd = new FormData();
    for (const [k, v] of Object.entries(editor.form)) {
        if (k === 'audience_ids') v.forEach((id) => fd.append('audience_ids[]', id));
        else if (typeof v === 'boolean') fd.append(k, v ? '1' : '0');
        else if (v !== '' && v !== null) fd.append(k, v);
    }
    if (editor.file) fd.append('file', editor.file);
    try {
        // Multipart update goes through POST (PHP does not parse multipart PUT bodies).
        editor.id ? await api.post(`/documents/company/${editor.id}`, fd) : await api.post('/documents/company', fd);
        ui.toast(editor.id ? 'Document updated.' : 'Document published.');
        editor.open = false;
        load();
    } catch (e) {
        editor.errors = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        editor.saving = false;
    }
}

// ---- acknowledgements --------------------------------------------------------------------
const acks = reactive({ doc: null, rows: [], summary: {}, loading: false, filter: 'pending', reminding: false });
const ackRows = computed(() => acks.rows.filter((r) => (acks.filter === 'pending' ? !r.acknowledged_at : acks.filter === 'done' ? !!r.acknowledged_at : true)));
async function openAcks(doc) {
    Object.assign(acks, { doc, rows: [], summary: {}, loading: true, filter: 'pending' });
    try {
        const { data } = await api.get(`/documents/company/${doc.id}/acknowledgements`);
        acks.rows = data.data ?? [];
        acks.summary = data.summary ?? {};
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        acks.loading = false;
    }
}
async function remind() {
    acks.reminding = true;
    try {
        ui.toast((await api.post(`/documents/company/${acks.doc.id}/remind`)).data.message);
        acks.summary.last_reminded_at = new Date().toISOString();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        acks.reminding = false;
    }
}

const del = reactive({ row: null, loading: false });
async function remove() {
    del.loading = true;
    try {
        ui.toast((await api.delete(`/documents/company/${del.row.id}`)).data.message);
        del.row = null;
        load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        del.loading = false;
    }
}

onMounted(() => { load(); loadLookups(); });
</script>

<template>
    <div>
        <PageHeader title="Memos & policies" subtitle="Publish company documents to all staff, a department or a branch, and track who has acknowledged them.">
            <button class="btn-primary" @click="open()"><Plus class="size-4" /> New {{ singular[tab] }}</button>
        </PageHeader>
        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <DataTable :columns="columns" :rows="rows" :loading="loading" :export-name="`${tab}s`" :empty-title="`No ${tab}s yet`" :empty-description="`Publish the first ${singular[tab]} with the button above.`">
            <template #toolbar>
                <div class="relative w-full sm:w-72">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="search" class="input !py-2 !pl-9" placeholder="Search titles…" aria-label="Search documents" @keyup.enter="load" />
                </div>
            </template>
            <template #cell-title="{ row, value }">
                <div class="flex items-start gap-2">
                    <FileText class="mt-0.5 size-4 shrink-0 text-slate-400" />
                    <div class="min-w-0">
                        <p class="font-medium text-slate-900 dark:text-white">{{ value }}</p>
                        <p class="truncate text-xs text-slate-500">{{ row.file_name }} · {{ row.size_human }}<span v-if="row.referred_by"> · {{ row.referred_by }}</span></p>
                    </div>
                </div>
            </template>
            <template #cell-effective_date="{ value }">{{ value ? formatDate(value) : '—' }}</template>
            <template #cell-audience="{ row }">{{ audienceText(row) }}</template>
            <template #cell-ack_stats="{ row }">
                <button v-if="row.requires_acknowledgement" class="text-left" @click="openAcks(row)">
                    <span class="text-sm font-medium" :class="row.ack_stats?.percent === 100 ? 'text-emerald-600' : 'text-slate-700 dark:text-slate-200'">{{ row.ack_stats?.acknowledged ?? 0 }}/{{ row.ack_stats?.audience ?? 0 }}</span>
                    <span class="block h-1.5 w-24 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><span class="block h-full bg-emerald-500" :style="{ width: `${row.ack_stats?.percent ?? 0}%` }" /></span>
                </button>
                <span v-else class="text-xs text-slate-400">Not required</span>
            </template>
            <template #cell-version="{ value }">v{{ value }}</template>
            <template #actions="{ row }">
                <button class="btn-ghost !p-1.5" title="Download" @click="openServerFile(`/api/documents/company/${row.id}/download`)"><Download class="size-4" /></button>
                <button v-if="row.requires_acknowledgement" class="btn-ghost !p-1.5" title="Acknowledgements" @click="openAcks(row)"><Users class="size-4" /></button>
                <button class="btn-ghost !p-1.5" title="Edit" @click="open(row)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !p-1.5 !text-rose-600" title="Delete" @click="del.row = row"><Trash2 class="size-4" /></button>
            </template>
        </DataTable>

        <Modal :open="editor.open" :title="editor.id ? 'Edit document' : `New ${singular[editor.form.category] ?? 'document'}`" size="lg" @close="editor.open = false">
            <form novalidate class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
                <div>
                    <label class="label">Type</label>
                    <select v-model="editor.form.category" class="input"><option value="memo">Memo</option><option value="newsletter">Newsletter</option><option value="policy">Policy</option></select>
                </div>
                <div>
                    <label class="label">Effective date</label>
                    <input v-model="editor.form.effective_date" type="date" class="input" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Title <span class="text-rose-500">*</span></label>
                    <input v-model="editor.form.title" class="input" />
                    <p v-if="editor.errors.title" class="field-error">{{ editor.errors.title }}</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Summary</label>
                    <textarea v-model="editor.form.description" rows="3" class="input" />
                </div>
                <div>
                    <label class="label">Issued by (department)</label>
                    <select v-model="editor.form.referred_by_department_id" class="input">
                        <option value="">—</option>
                        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="label">Audience</label>
                    <select v-model="editor.form.audience" class="input" @change="editor.form.audience_ids = []">
                        <option value="all">All staff</option><option value="department">Selected departments</option><option value="branch">Selected branches</option>
                    </select>
                </div>
                <div v-if="editor.form.audience !== 'all'" class="sm:col-span-2">
                    <p class="label">{{ editor.form.audience === 'branch' ? 'Branches' : 'Departments' }}</p>
                    <div class="flex max-h-40 flex-wrap gap-2 overflow-y-auto">
                        <label v-for="o in editor.form.audience === 'branch' ? branches : departments" :key="o.id" class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-sm dark:border-slate-700">
                            <input v-model="editor.form.audience_ids" type="checkbox" :value="o.id" class="rounded text-brand-600" /> {{ o.name }}
                        </label>
                    </div>
                    <p v-if="editor.errors.audience_ids" class="field-error">{{ editor.errors.audience_ids }}</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="label">File {{ editor.id ? '(upload to replace; the old version is kept)' : '' }} <span v-if="!editor.id" class="text-rose-500">*</span></label>
                    <input type="file" accept=".pdf,.doc,.docx,.odt,.jpg,.jpeg,.png" class="input" @change="editor.file = $event.target.files[0] ?? null" />
                    <p v-if="editor.errors.file" class="field-error">{{ editor.errors.file }}</p>
                    <p v-else class="mt-1 text-xs text-slate-400">PDF, Word, ODT, JPG or PNG, up to 50 MB.</p>
                </div>
                <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="editor.form.requires_acknowledgement" type="checkbox" class="rounded text-brand-600" /> Staff must acknowledge they have read it</label>
                <label v-if="editor.id && editor.file && editor.form.requires_acknowledgement" class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="editor.form.reset_acknowledgements" type="checkbox" class="rounded text-brand-600" /> Ask everyone to acknowledge the new version again</label>
                <label v-if="!editor.id" class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="editor.form.notify" type="checkbox" class="rounded text-brand-600" /> Notify the audience now</label>
                <button type="submit" class="hidden" />
            </form>
            <template #footer>
                <button class="btn-secondary" @click="editor.open = false">Cancel</button>
                <button class="btn-primary" :disabled="editor.saving" @click="save"><Spinner v-if="editor.saving" /> {{ editor.id ? 'Save changes' : 'Publish' }}</button>
            </template>
        </Modal>

        <Modal :open="!!acks.doc" :title="`Acknowledgements: ${acks.doc?.title ?? ''}`" size="lg" @close="acks.doc = null">
            <div v-if="acks.loading" class="flex justify-center py-10"><Spinner /></div>
            <template v-else>
                <div class="mb-4 flex flex-wrap items-center gap-3">
                    <p class="text-sm"><strong>{{ acks.summary.acknowledged }}</strong> of {{ acks.summary.audience }} acknowledged ({{ acks.summary.percent }}%)</p>
                    <select v-model="acks.filter" class="input !w-40 !py-1.5" aria-label="Filter">
                        <option value="pending">Pending</option><option value="done">Acknowledged</option><option value="all">Everyone</option>
                    </select>
                    <span v-if="acks.summary.last_reminded_at" class="text-xs text-slate-400">Last reminder {{ formatDate(acks.summary.last_reminded_at) }}</span>
                </div>
                <ul class="max-h-80 divide-y divide-slate-100 overflow-y-auto text-sm dark:divide-slate-800">
                    <li v-for="r in ackRows" :key="r.employee_id" class="flex items-center gap-3 py-2">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ r.full_name }} <span class="text-xs font-normal text-slate-400">{{ r.employee_number }}</span></p>
                            <p class="truncate text-xs text-slate-500">{{ [r.department, r.branch].filter(Boolean).join(' · ') }}<span v-if="!r.has_account"> · no login</span></p>
                        </div>
                        <Badge v-if="r.acknowledged_at" tone="green">{{ formatDate(r.acknowledged_at) }}</Badge>
                        <Badge v-else tone="amber">Pending</Badge>
                    </li>
                    <li v-if="!ackRows.length" class="py-6 text-center text-slate-400">Nobody here.</li>
                </ul>
            </template>
            <template #footer>
                <button class="btn-secondary" @click="acks.doc = null">Close</button>
                <button class="btn-primary" :disabled="acks.reminding || !acks.summary.pending" @click="remind"><Spinner v-if="acks.reminding" /><BellRing v-else class="size-4" /> Remind pending</button>
            </template>
        </Modal>

        <ConfirmDialog :open="!!del.row" title="Delete document" :message="`Delete “${del.row?.title}”? Staff will no longer see it.`" confirm-label="Delete" danger
            :loading="del.loading" @confirm="remove" @close="del.row = null" />
    </div>
</template>
