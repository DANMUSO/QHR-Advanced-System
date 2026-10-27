<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, onMounted } from 'vue';
import { Plus } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { formatDate, titleCase } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';

/** My grievances: file one (optionally anonymously) and follow its status. */
const rows = ref([]);
const loading = ref(false);
const open = ref(false);
const form = ref({});
const errors = useFormErrors(() => form.value);
const saving = ref(false);
const categories = ['workplace', 'harassment', 'discrimination', 'pay', 'working_conditions', 'other'];
const statusColors = { open: 'yellow', investigating: 'blue', mediation: 'purple', resolved: 'green', escalated: 'red' };

async function load() {
    loading.value = true;
    try { const { data } = await api.get('/my/grievances'); rows.value = data.data ?? []; }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}
function create() { form.value = { category: 'workplace', subject: '', description: '', anonymous: false }; errors.value = {}; open.value = true; }
async function save() {
    saving.value = true; errors.value = {};
    try { const { data } = await api.post('/my/grievances', form.value); swal.toast(data.message ?? 'Grievance filed.'); open.value = false; await load(); }
    catch (e) { errors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}
onMounted(load);
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-slate-500">Raise a workplace concern with HR. It is handled confidentially.</p>
            <button class="btn-primary" @click="create"><Plus class="size-4" /> File a grievance</button>
        </div>
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else-if="!rows.length" class="card p-10 text-center text-slate-400">You have not filed any grievances.</div>
        <div v-else class="space-y-3">
            <div v-for="g in rows" :key="g.id" class="card p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="font-medium text-slate-900 dark:text-white">{{ g.subject ?? titleCase(g.category) }}</p>
                    <Badge :color="statusColors[g.status] ?? 'slate'">{{ titleCase(g.status) }}</Badge>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ g.reference }}<span v-if="g.category"> · {{ titleCase(g.category) }}</span> · {{ formatDate(g.filed_date ?? g.created_at) }}<span v-if="g.anonymous"> · anonymous</span></p>
                <p class="mt-2 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ g.description }}</p>
                <p v-if="g.resolution" class="mt-2 rounded-lg bg-emerald-50 p-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">Resolution: {{ g.resolution }}</p>
            </div>
        </div>

        <Modal :open="open" title="File a grievance" @close="open = false">
            <div class="space-y-4">
                <div><label class="label">Category</label><select v-model="form.category" class="input"><option v-for="c in categories" :key="c" :value="c">{{ titleCase(c) }}</option></select></div>
                <div><label class="label">Subject</label><input v-model="form.subject" class="input" /><p v-if="errors.subject" class="field-error">{{ errors.subject }}</p></div>
                <div><label class="label">Description</label><textarea v-model="form.description" rows="5" class="input" /><p v-if="errors.description" class="field-error">{{ errors.description }}</p></div>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.anonymous" type="checkbox" class="rounded" /> File anonymously</label>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Submitting…' : 'Submit' }}</button>
            </template>
        </Modal>
    </div>
</template>
