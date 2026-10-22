<script setup>
import { ref, onMounted } from 'vue';
import { BookOpen, Plus } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { titleCase } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import Tabs from '@/components/ui/Tabs.vue';

/** My training: progress updates on my enrolments, and self-enrolment from the published catalogue. */
const tab = ref('mine');
const tabs = [{ key: 'mine', label: 'My Training' }, { key: 'catalogue', label: 'Course Catalogue' }];
const training = ref([]);
const catalogue = ref([]);
const loading = ref(false);
const statusColors = { enrolled: 'yellow', in_progress: 'blue', completed: 'green', cancelled: 'slate' };

const progress = ref({ open: false, item: null, value: 0, status: 'in_progress' });
const saving = ref(false);

async function loadMine() {
    loading.value = true;
    try { const { data } = await api.get('/performance/learning/my'); training.value = data.data ?? []; }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}
async function loadCatalogue() {
    loading.value = true;
    try { const { data } = await api.get('/performance/learning/catalogue'); catalogue.value = data.data ?? []; }
    catch (e) { catalogue.value = []; swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}
function switchTab(t) { tab.value = t; t === 'mine' ? loadMine() : loadCatalogue(); }

function openProgress(item) {
    progress.value = { open: true, item, value: item.progress ?? 0, status: item.status === 'enrolled' ? 'in_progress' : item.status };
}
async function saveProgress() {
    saving.value = true;
    const status = Number(progress.value.value) >= 100 ? 'completed' : progress.value.status;
    try {
        const { data } = await api.put(`/performance/learning/my/${progress.value.item.id}`, { progress: Number(progress.value.value), status });
        swal.toast(data.message ?? 'Progress updated.'); progress.value.open = false; await loadMine();
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}

async function enrol(course) {
    if (!await swal.confirm({ title: `Enrol in ${course.name}?`, confirmLabel: 'Enrol', danger: false })) return;
    try {
        const { data } = await api.post(`/performance/learning/catalogue/${course.id}/enrol`);
        swal.toast(data.message ?? 'Enrolled.'); switchTab('mine');
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
}

onMounted(loadMine);
</script>

<template>
    <div>
        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>

        <template v-else-if="tab === 'mine'">
            <div v-if="!training.length" class="card p-10 text-center text-slate-400">
                No training enrolled. <button class="text-brand-600 hover:underline dark:text-brand-400" @click="switchTab('catalogue')">Browse the catalogue</button>
            </div>
            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="item in training" :key="item.id" class="card flex flex-col p-5">
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <h3 class="font-semibold text-slate-900 dark:text-white">{{ item.course_name }}</h3>
                        <Badge :color="statusColors[item.status] ?? 'slate'">{{ titleCase(item.status) }}</Badge>
                    </div>
                    <p class="text-sm text-slate-500">{{ item.provider ?? '—' }}<span v-if="item.delivery"> · {{ titleCase(item.delivery) }}</span></p>
                    <p v-if="item.start_date" class="mt-1 text-sm text-slate-500">{{ item.start_date }} – {{ item.end_date ?? '…' }}</p>
                    <div class="mt-3">
                        <div class="mb-1 flex justify-between text-xs text-slate-500"><span>Progress</span><span>{{ item.progress ?? 0 }}%</span></div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full rounded-full bg-brand-500" :style="{ width: `${item.progress ?? 0}%` }" /></div>
                    </div>
                    <button v-if="!['completed', 'cancelled'].includes(item.status)" class="btn-secondary mt-4 !py-1.5" @click="openProgress(item)">Update progress</button>
                </div>
            </div>
        </template>

        <template v-else>
            <div v-if="!catalogue.length" class="card p-10 text-center text-slate-400">No courses are open for self-enrolment right now.</div>
            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="c in catalogue" :key="c.id" class="card flex flex-col p-5">
                    <div class="mb-2 flex items-start gap-2"><BookOpen class="mt-0.5 size-4 shrink-0 text-brand-600" /><h3 class="font-semibold text-slate-900 dark:text-white">{{ c.name }}</h3></div>
                    <p class="text-sm text-slate-500">{{ c.provider ?? '—' }}<span v-if="c.category"> · {{ titleCase(c.category) }}</span><span v-if="c.duration_hours"> · {{ c.duration_hours }}h</span></p>
                    <p v-if="c.description" class="mt-2 line-clamp-3 text-sm text-slate-600 dark:text-slate-300">{{ c.description }}</p>
                    <div class="mt-auto pt-4">
                        <Badge v-if="c.enrollment_status" color="green">{{ titleCase(c.enrollment_status) }}</Badge>
                        <p v-else-if="c.can_enrol === false" class="text-xs text-slate-400">Not open for enrolment</p>
                        <button v-else class="btn-primary w-full !py-1.5" @click="enrol(c)"><Plus class="size-4" /> Enrol</button>
                    </div>
                </div>
            </div>
        </template>

        <Modal :open="progress.open" title="Update progress" size="sm" @close="progress.open = false">
            <p class="mb-3 text-sm text-slate-500">{{ progress.item?.course_name }}</p>
            <label class="label">Progress: {{ progress.value }}%</label>
            <input v-model="progress.value" type="range" min="0" max="100" step="5" class="w-full accent-brand-600" />
            <label class="label mt-4">Status</label>
            <select v-model="progress.status" class="input"><option value="in_progress">In progress</option><option value="completed">Completed</option></select>
            <template #footer>
                <button class="btn-secondary" @click="progress.open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="saveProgress">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </Modal>
    </div>
</template>
