<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Upload, Download, FileText, Search, FolderOpen } from 'lucide-vue-next';
import { openServerFile } from '@/lib/export';
import Avatar from '@/components/ui/Avatar.vue';

const route = useRoute();
const router = useRouter();
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';

const data = ref(null);
const loading = ref(true);
const uploadModal = ref(false);
const form = ref({ title: '', document_type_id: '', notes: '', file: null, is_confidential: false });
const errors = useFormErrors(() => form.value);
const uploading = ref(false);

// Without an employee in the URL, show a picker instead of a blank page.
const employees = ref([]);
const search = ref('');
const filtered = computed(() => {
    const q = search.value.toLowerCase().trim();
    return employees.value.filter((e) => !q || e.name.toLowerCase().includes(q) || (e.employee_number ?? '').toLowerCase().includes(q)).slice(0, 60);
});

async function load() {
    if (!route.params.id) {
        data.value = null;
        loading.value = true;
        try { employees.value = (await api.get('/lookups', { params: { only: 'employees' } })).data.employees ?? []; }
        finally { loading.value = false; }
        return;
    }
    loading.value = true;
    try {
        const { data: d } = await api.get(`/documents/employees/${route.params.id}`);
        data.value = d;
    } finally {
        loading.value = false;
    }
}

async function upload() {
    uploading.value = true;
    errors.value = {};
    try {
        const fd = new FormData();
        Object.entries(form.value).forEach(([k, v]) => { if (v !== null && v !== '') fd.append(k, v); });
        await api.post(`/documents/employees/${route.params.id}`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
        swal.toast('Document uploaded.');
        uploadModal.value = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        swal.toast(errorMessage(e), 'error');
    } finally {
        uploading.value = false;
    }
}

function download(doc) {
    openServerFile(`/api/documents/employee-docs/${doc.id}/download`);
}

onMounted(load);
watch(() => route.params.id, load);
</script>

<template>
    <div>
        <PageHeader
            :title="data ? `${data.employee.full_name} — Documents` : 'Employee Files'"
            :subtitle="route.params.id ? null : 'Choose an employee to open their personal file.'"
            :back="route.params.id ? '/documents/employees' : '/employees'"
        >
            <template v-if="route.params.id" #actions>
                <button class="btn-primary" @click="uploadModal = true; form = { title: '', document_type_id: '', notes: '', file: null, is_confidential: false }">
                    <Upload class="size-4" /> Upload
                </button>
            </template>
        </PageHeader>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>

        <!-- Employee picker (no employee selected) -->
        <div v-else-if="!route.params.id" class="card p-4">
            <div class="relative mb-4 max-w-md">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input v-model="search" class="input pl-9" placeholder="Search by name or employee number" aria-label="Search employees" />
            </div>
            <p v-if="!filtered.length" class="py-10 text-center text-sm text-slate-500">
                {{ search ? `No employees match “${search}”.` : 'No employees found.' }}
            </p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="e in filtered"
                    :key="e.id"
                    class="flex items-center gap-3 rounded-xl border border-slate-100 p-3 text-left transition hover:border-brand-200 hover:bg-brand-50/50 dark:border-slate-800 dark:hover:bg-slate-800"
                    @click="router.push(`/documents/employees/${e.id}`)"
                >
                    <Avatar :name="e.name" :src="e.photo_url" size="sm" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ e.name }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ e.employee_number }}</span>
                    </span>
                    <FolderOpen class="size-4 text-slate-400" />
                </button>
            </div>
        </div>
        <div v-else-if="data" class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left">Document</th>
                        <th class="px-4 py-3 text-left">Type</th>
                        <th class="px-4 py-3 text-left">Expires</th>
                        <th class="px-4 py-3 text-left">Source</th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="doc in data.documents" :key="doc.id" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <FileText class="size-4 text-slate-400" />
                                <span class="font-medium">{{ doc.title }}</span>
                                <Badge v-if="doc.is_confidential" color="red">Confidential</Badge>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ doc.type ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span v-if="doc.expiry_date" :class="doc.expiry_status === 'expired' ? 'text-red-500' : doc.expiry_status === 'expiring' ? 'text-orange-500' : 'text-slate-500'">{{ doc.expiry_date }}</span>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="px-4 py-3"><Badge :color="doc.source === 'hr' ? 'blue' : 'slate'">{{ doc.source_label }}</Badge></td>
                        <td class="px-4 py-3 text-right">
                            <button class="btn-ghost !p-1.5" @click="download(doc)"><Download class="size-4" /></button>
                        </td>
                    </tr>
                    <!-- HR-generated letters (probation confirmation, employment, ...) -->
                    <tr v-for="l in data.letters ?? []" :key="`letter-${l.id}`" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <FileText class="size-4 text-slate-400" />
                                <span class="font-medium">{{ l.subject }}</span>
                                <span class="text-xs text-slate-400">{{ l.reference }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-slate-500">Letter · {{ l.template_name }}</td>
                        <td class="px-4 py-3 text-slate-400">—</td>
                        <td class="px-4 py-3"><Badge color="blue">Generated by HR</Badge></td>
                        <td class="px-4 py-3 text-right">
                            <button class="btn-ghost !p-1.5" @click="openServerFile(l.path)"><Download class="size-4" /></button>
                        </td>
                    </tr>
                    <tr v-if="!data.documents?.length && !data.letters?.length">
                        <td colspan="5" class="px-4 py-10 text-center text-slate-400">No documents in this employee's file.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :open="uploadModal" title="Upload to Employee File" @close="uploadModal = false">
            <div class="space-y-4">
                <div>
                    <label class="label">Title</label>
                    <input v-model="form.title" type="text" class="input" required />
                </div>
                <div>
                    <label class="label">File</label>
                    <input type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="input" @change="form.file = $event.target.files[0]" required />
                </div>
                <div>
                    <label class="label">Notes</label>
                    <textarea v-model="form.notes" rows="2" class="input" />
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_confidential" type="checkbox" class="rounded" />
                    Mark as confidential
                </label>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="uploadModal = false">Cancel</button>
                <button class="btn-primary" :disabled="uploading" @click="upload">{{ uploading ? 'Uploading…' : 'Upload' }}</button>
            </template>
        </Modal>
    </div>
</template>
