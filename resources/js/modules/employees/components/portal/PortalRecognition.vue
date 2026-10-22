<script setup>
import { ref, onMounted } from 'vue';
import { Award, Plus } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { timeAgo, titleCase } from '@/lib/format';
import Avatar from '@/components/ui/Avatar.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import Tabs from '@/components/ui/Tabs.vue';
import LookupSelect from '@/components/ui/LookupSelect.vue';

/** Recognition I received and gave, and recognising a colleague. */
const tab = ref('received');
const tabs = [{ key: 'received', label: 'Received' }, { key: 'given', label: 'Given' }];
const data = ref({ received: [], given: [] });
const loading = ref(false);
const open = ref(false);
const form = ref({});
const errors = ref({});
const saving = ref(false);
const categories = ref(['star_performer', 'innovation', 'teamwork', 'customer_service', 'leadership']);

async function load() {
    loading.value = true;
    try { const res = await api.get('/performance/recognition/my'); data.value = { received: res.data.received ?? [], given: res.data.given ?? [] }; if (res.data.categories?.length) categories.value = res.data.categories; }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}
function create() { form.value = { employee_id: '', category: 'teamwork', title: '', message: '' }; errors.value = {}; open.value = true; }
async function save() {
    saving.value = true; errors.value = {};
    try { const res = await api.post('/performance/recognition', form.value); swal.toast(res.data.message ?? 'Recognition sent.'); open.value = false; tab.value = 'given'; await load(); }
    catch (e) { errors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}
onMounted(load);
</script>

<template>
    <div>
        <div class="flex flex-wrap items-start justify-between gap-2">
            <Tabs v-model="tab" class="min-w-0 flex-1" :tabs="tabs.map((t) => ({ ...t, count: data[t.key]?.length || null }))" />
            <button class="btn-primary mb-4" @click="create"><Plus class="size-4" /> Recognise a colleague</button>
        </div>
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else-if="!data[tab]?.length" class="card p-10 text-center text-slate-400">{{ tab === 'received' ? 'No recognition received yet.' : 'You have not recognised anyone yet.' }}</div>
        <div v-else class="grid gap-3 sm:grid-cols-2">
            <div v-for="r in data[tab]" :key="r.id" class="card p-4">
                <div class="flex items-start gap-3">
                    <Avatar v-if="tab === 'given'" :src="r.employee?.photo_url" :name="r.employee?.name" size="md" />
                    <div v-else class="rounded-xl bg-amber-50 p-2 text-amber-600 dark:bg-amber-500/15"><Award class="size-5" /></div>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-slate-900 dark:text-white">{{ r.title ?? titleCase(r.category) }}</p>
                        <p class="text-xs text-slate-500">{{ tab === 'given' ? `To ${r.employee?.name ?? 'colleague'}` : `From ${r.awarded_by ?? 'a colleague'}` }} · {{ titleCase(r.category) }} · {{ timeAgo(r.created_at) }}</p>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ r.message }}</p>
                    </div>
                </div>
            </div>
        </div>

        <Modal :open="open" title="Recognise a colleague" @close="open = false">
            <div class="space-y-4">
                <div>
                    <label class="label">Colleague</label>
                    <LookupSelect v-model="form.employee_id" kind="employees" placeholder="Search colleagues…" />
                    <p v-if="errors.employee_id" class="field-error">{{ errors.employee_id }}</p>
                </div>
                <div><label class="label">Category</label><select v-model="form.category" class="input"><option v-for="c in categories" :key="c" :value="c">{{ titleCase(c) }}</option></select></div>
                <div><label class="label">Title</label><input v-model="form.title" class="input" placeholder="e.g. Saved the quarter-end close" /><p v-if="errors.title" class="field-error">{{ errors.title }}</p></div>
                <div><label class="label">Message</label><textarea v-model="form.message" rows="4" class="input" /><p v-if="errors.message" class="field-error">{{ errors.message }}</p></div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving || !form.employee_id" @click="save">{{ saving ? 'Sending…' : 'Send' }}</button>
            </template>
        </Modal>
    </div>
</template>
