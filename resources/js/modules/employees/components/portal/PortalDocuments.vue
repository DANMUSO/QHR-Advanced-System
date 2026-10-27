<script setup>
import { ref, onMounted } from 'vue';
import { Upload, Download, FileText, CheckCircle2 } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { formatMoney, titleCase } from '@/lib/format';
import { openServerFile } from '@/lib/export';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import Tabs from '@/components/ui/Tabs.vue';

/** My files, company policies (with acknowledgement), my contracts and my letters. */
const tab = ref('mine');
const tabs = ref([
    { key: 'mine', label: 'My Files' },
    { key: 'company', label: 'Company Policies' },
    { key: 'contracts', label: 'My Contracts' },
    { key: 'letters', label: 'My Letters' },
]);
const loading = ref(false);
const docData = ref(null);
const companyDocs = ref([]);
const contracts = ref([]);
const letters = ref([]);

const uploadOpen = ref(false);
const uploadForm = ref({});
const uploadErrors = ref({});
const uploading = ref(false);

const contractColors = { active: 'green', draft: 'slate', expired: 'orange', superseded: 'slate', terminated: 'red' };

async function load() {
    loading.value = true;
    try {
        if (tab.value === 'mine') { const { data } = await api.get('/documents/my-file'); docData.value = data; }
        else if (tab.value === 'company') {
            const { data } = await api.get('/documents/company', { params: { view: 'staff', per_page: 100 } });
            companyDocs.value = data.data ?? [];
            tabs.value[1].count = data.pending_acknowledgements || null;
        } else if (tab.value === 'contracts') { const { data } = await api.get('/profile/contracts'); contracts.value = data.data ?? []; }
        else { const { data } = await api.get('/profile/requests'); letters.value = data.letters ?? []; }
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}
function switchTab(t) { tab.value = t; load(); }

function openUpload() { uploadForm.value = { title: '', document_type_id: '', notes: '', file: null }; uploadErrors.value = {}; uploadOpen.value = true; }
async function upload() {
    uploading.value = true; uploadErrors.value = {};
    try {
        const fd = new FormData();
        Object.entries(uploadForm.value).forEach(([k, v]) => { if (v !== null && v !== '') fd.append(k, v); });
        await api.post('/documents/employees/me', fd);
        swal.toast('Document uploaded.'); uploadOpen.value = false; await load();
    } catch (e) { uploadErrors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { uploading.value = false; }
}

async function acknowledge(doc) {
    if (!await swal.confirm({ title: `Acknowledge "${doc.title}"?`, text: 'You confirm you have read and understood this document.', confirmLabel: 'I acknowledge', danger: false })) return;
    try { const { data } = await api.post(`/documents/company/${doc.id}/acknowledge`); swal.toast(data.message); await load(); }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
}

onMounted(load);
</script>

<template>
    <div>
        <div class="flex flex-wrap items-start justify-between gap-2">
            <Tabs class="min-w-0 flex-1" :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />
            <button v-if="tab === 'mine'" class="btn-primary mb-4" @click="openUpload"><Upload class="size-4" /> Upload</button>
        </div>
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>

        <template v-else-if="tab === 'mine' && docData">
            <div v-if="docData.checklist?.length" class="mb-4 flex flex-wrap gap-2">
                <Badge v-for="item in docData.checklist" :key="item.document_type_id" :color="item.status === 'present' ? 'green' : item.status === 'expired' ? 'orange' : 'red'">{{ item.name }}</Badge>
            </div>
            <div class="space-y-2">
                <div v-for="doc in docData.documents" :key="doc.id" class="card flex items-center gap-3 p-3 sm:p-4">
                    <FileText class="size-5 shrink-0 text-slate-400" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-slate-900 dark:text-white">{{ doc.title }}</p>
                        <p class="text-xs text-slate-500">
                            {{ doc.type ?? 'Document' }}<span v-if="doc.issue_date"> · issued {{ doc.issue_date }}</span>
                            <span v-if="doc.expiry_date" :class="doc.expiry_status === 'expired' ? 'text-red-500' : doc.expiry_status === 'expiring' ? 'text-orange-500' : ''"> · expires {{ doc.expiry_date }}</span>
                        </p>
                    </div>
                    <button class="btn-ghost !p-1.5" title="Download" @click="openServerFile(`/api/documents/employee-docs/${doc.id}/download`)"><Download class="size-4" /></button>
                </div>
                <div v-for="l in docData.letters ?? []" :key="`letter-${l.id}`" class="card flex items-center gap-3 p-3 sm:p-4">
                    <FileText class="size-5 shrink-0 text-brand-500" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-slate-900 dark:text-white">{{ l.subject }}</p>
                        <p class="text-xs text-slate-500">Letter · {{ l.template_name }} · {{ l.reference }}</p>
                    </div>
                    <button class="btn-ghost !p-1.5" title="Download" @click="openServerFile(l.path)"><Download class="size-4" /></button>
                </div>
                <div v-if="!docData.documents?.length && !docData.letters?.length" class="card p-10 text-center text-slate-400">No documents uploaded yet.</div>
            </div>
        </template>

        <div v-else-if="tab === 'company'" class="space-y-2">
            <div v-for="doc in companyDocs" :key="doc.id" class="card flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:p-4">
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <FileText class="size-5 shrink-0 text-slate-400" />
                    <div class="min-w-0">
                        <p class="truncate font-medium text-slate-900 dark:text-white">{{ doc.title }}</p>
                        <p class="text-xs text-slate-400">{{ titleCase(doc.category) }}<span v-if="doc.effective_date"> · {{ doc.effective_date }}</span><span v-if="doc.version"> · v{{ doc.version }}</span></p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <template v-if="doc.requires_acknowledgement">
                        <Badge v-if="doc.acknowledged" color="green"><CheckCircle2 class="size-3" /> Acknowledged</Badge>
                        <button v-else class="btn-primary !py-1.5" :disabled="!doc.viewed" :title="doc.viewed ? '' : 'Open the document first: you can acknowledge it once you have read it.'" @click="acknowledge(doc)">Acknowledge</button>
                    </template>
                    <button class="btn-ghost !p-1.5" title="Open / download" @click="openServerFile(`/api/documents/company/${doc.id}/download?inline=1`); doc.viewed = true"><Download class="size-4" /></button>
                </div>
            </div>
            <div v-if="!companyDocs.length" class="card p-10 text-center text-slate-400">No company documents available.</div>
        </div>

        <div v-else-if="tab === 'contracts'" class="space-y-2">
            <div v-for="c in contracts" :key="c.id" class="card flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:p-4">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2"><p class="font-medium text-slate-900 dark:text-white">{{ c.job_title ?? c.contract_type_label }}</p><Badge :color="contractColors[c.status] ?? 'slate'">{{ c.status }}</Badge></div>
                    <p class="text-xs text-slate-500">{{ c.reference }} · {{ c.contract_type_label }} · {{ c.start_date }} – {{ c.end_date ?? 'open-ended' }}<span v-if="c.gross_salary != null"> · {{ formatMoney(c.gross_salary) }}</span></p>
                </div>
                <button class="btn-secondary !py-1.5" @click="openServerFile(c.path)"><Download class="size-4" /> PDF</button>
            </div>
            <div v-if="!contracts.length" class="card p-10 text-center text-slate-400">No contracts on file.</div>
        </div>

        <div v-else class="space-y-2">
            <div v-for="l in letters" :key="l.id" class="card flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:p-4">
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-slate-900 dark:text-white">{{ l.template_name }}</p>
                    <p class="text-xs text-slate-500">{{ l.reference }} · {{ l.effective_date }}</p>
                </div>
                <button class="btn-secondary !py-1.5" @click="openServerFile(l.path)"><Download class="size-4" /> PDF</button>
            </div>
            <div v-if="!letters.length" class="card p-10 text-center text-slate-400">No letters yet. You can request one under Requests.</div>
        </div>

        <Modal :open="uploadOpen" title="Upload document" @close="uploadOpen = false">
            <div class="space-y-4">
                <div><label class="label">Title</label><input v-model="uploadForm.title" type="text" class="input" /><p v-if="uploadErrors.title" class="field-error">{{ uploadErrors.title }}</p></div>
                <div><label class="label">Document type</label><select v-model="uploadForm.document_type_id" class="input"><option value="">— Select type —</option><option v-for="t in docData?.checklist" :key="t.document_type_id" :value="t.document_type_id">{{ t.name }}</option></select></div>
                <div><label class="label">File</label><input type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="input" @change="uploadForm.file = $event.target.files[0]" /><p v-if="uploadErrors.file" class="field-error">{{ uploadErrors.file }}</p></div>
                <div><label class="label">Notes</label><textarea v-model="uploadForm.notes" rows="2" class="input" /></div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="uploadOpen = false">Cancel</button>
                <button class="btn-primary" :disabled="uploading" @click="upload">{{ uploading ? 'Uploading…' : 'Upload' }}</button>
            </template>
        </Modal>
    </div>
</template>
