<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, onMounted } from 'vue';
import { Plus, Send } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';

const comments = ref([]);
const categories = ref({});
const loading = ref(true);
const newModal = ref(false);
const form = ref({ category: '', body: '', anonymous: false });
const errors = useFormErrors(() => form.value);
const submitting = ref(false);
const replyDrafts = ref({});
const replying = ref(null);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/engagement/comments/mine');
        comments.value = data.data;
        categories.value = data.categories;
    } finally {
        loading.value = false;
    }
}

async function submit() {
    submitting.value = true;
    errors.value = {};
    try {
        await api.post('/engagement/comments', form.value);
        swal.toast('Comment submitted.');
        newModal.value = false;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        swal.toast(errorMessage(e), 'error');
    } finally {
        submitting.value = false;
    }
}

/** Follow up in my own thread; anonymous threads stay anonymous to HR. */
async function respond(c) {
    const body = (replyDrafts.value[c.id] ?? '').trim();
    if (!body) return;
    replying.value = c.id;
    try {
        const { data } = await api.post(`/engagement/comments/${c.id}/respond`, { body });
        comments.value = comments.value.map((x) => (x.id === c.id ? data.data : x));
        replyDrafts.value[c.id] = '';
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        replying.value = null;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Suggestion Box">
            <template #actions>
                <button class="btn-primary" @click="newModal = true; form = { category: '', body: '', anonymous: false }"><Plus class="size-4" /> New Comment</button>
            </template>
        </PageHeader>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else-if="!comments.length" class="card p-10 text-center text-slate-400">No comments submitted yet.</div>
        <div v-else class="space-y-4">
            <div v-for="c in comments" :key="c.id" class="card p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <Badge color="blue">{{ c.category_label }}</Badge>
                            <Badge :color="c.status === 'replied' ? 'green' : 'yellow'">{{ c.status }}</Badge>
                            <span v-if="c.anonymous" class="text-xs text-slate-400">Anonymous — HR cannot see it was you</span>
                            <Badge v-if="c.unread_replies" color="purple">{{ c.unread_replies }} new</Badge>
                        </div>
                        <p class="text-sm text-slate-700 dark:text-slate-300">{{ c.body }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ c.created_at?.slice(0, 10) }}</p>
                    </div>
                </div>
                <div v-if="c.replies?.length" class="mt-3 space-y-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <div v-for="r in c.replies" :key="r.id" class="rounded-lg p-3" :class="r.from_author ? 'ml-6 bg-brand-50 dark:bg-brand-500/10' : 'mr-6 bg-slate-50 dark:bg-slate-800/50'">
                        <p class="text-xs font-medium text-slate-500">{{ r.author }} · {{ r.created_at?.slice(0, 10) }}</p>
                        <p class="text-sm text-slate-700 dark:text-slate-200">{{ r.body }}</p>
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <input v-model="replyDrafts[c.id]" type="text" class="input flex-1" :placeholder="c.anonymous ? 'Reply anonymously…' : 'Reply to HR…'" maxlength="3000" @keyup.enter="respond(c)" />
                    <button class="btn-secondary shrink-0" :disabled="replying === c.id || !(replyDrafts[c.id] ?? '').trim()" @click="respond(c)"><Send class="size-4" /></button>
                </div>
            </div>
        </div>

        <Modal :open="newModal" title="Submit Comment / Suggestion" @close="newModal = false">
            <div class="space-y-4">
                <div>
                    <label class="label">Category</label>
                    <select v-model="form.category" class="input" required>
                        <option value="">— Select —</option>
                        <option v-for="[k,v] in Object.entries(categories)" :key="k" :value="k">{{ v }}</option>
                    </select>
                    <p v-if="errors.category" class="mt-1 text-xs text-red-500">{{ errors.category }}</p>
                </div>
                <div>
                    <label class="label">Your Comment</label>
                    <textarea v-model="form.body" rows="4" class="input" required />
                    <p v-if="errors.body" class="mt-1 text-xs text-red-500">{{ errors.body }}</p>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.anonymous" type="checkbox" class="rounded" />
                    Submit anonymously
                </label>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="newModal = false">Cancel</button>
                <button class="btn-primary" :disabled="submitting" @click="submit">{{ submitting ? 'Submitting…' : 'Submit' }}</button>
            </template>
        </Modal>
    </div>
</template>
