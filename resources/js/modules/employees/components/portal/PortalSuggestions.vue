<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, onMounted } from 'vue';
import { Plus, Send } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { formatDate } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';

/** Suggestion box: my comments (anonymous ones included, visible only to me), HR's replies, and my follow-ups. */
const rows = ref([]);
const categories = ref({});
const loading = ref(false);
const open = ref(false);
const form = ref({});
const errors = useFormErrors(() => form.value);
const saving = ref(false);
const replies = ref({});

async function load() {
    loading.value = true;
    try { const { data } = await api.get('/engagement/comments/mine'); rows.value = data.data ?? []; categories.value = data.categories ?? {}; }
    catch (e) { swal.toast(errorMessage(e), 'error'); }
    finally { loading.value = false; }
}
function create() { form.value = { category: '', body: '', anonymous: false }; errors.value = {}; open.value = true; }
async function save() {
    saving.value = true; errors.value = {};
    try { await api.post('/engagement/comments', form.value); swal.toast('Submitted.'); open.value = false; await load(); }
    catch (e) { errors.value = fieldErrors(e); swal.toast(errorMessage(e), 'error'); }
    finally { saving.value = false; }
}
async function reply(c) {
    const body = (replies.value[c.id] ?? '').trim();
    if (!body) return;
    try {
        const { data } = await api.post(`/engagement/comments/${c.id}/respond`, { body });
        replies.value[c.id] = '';
        const i = rows.value.findIndex((r) => r.id === c.id);
        if (i >= 0 && data.data) rows.value[i] = data.data; else await load();
        swal.toast(data.message ?? 'Reply sent.');
    } catch (e) { swal.toast(errorMessage(e), 'error'); }
}
onMounted(load);
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-slate-500">Share ideas or concerns. Anonymous comments stay anonymous to HR; only you see them here.</p>
            <button class="btn-primary" @click="create"><Plus class="size-4" /> New comment</button>
        </div>
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else-if="!rows.length" class="card p-10 text-center text-slate-400">No comments submitted yet.</div>
        <div v-else class="space-y-4">
            <div v-for="c in rows" :key="c.id" class="card p-4">
                <div class="mb-1 flex flex-wrap items-center gap-2">
                    <Badge color="blue">{{ c.category_label }}</Badge>
                    <Badge :color="c.status === 'replied' ? 'green' : 'yellow'">{{ c.status }}</Badge>
                    <Badge v-if="c.anonymous" color="slate">Anonymous</Badge>
                    <Badge v-if="c.unread_replies" color="red">{{ c.unread_replies }} new</Badge>
                    <span class="text-xs text-slate-400">{{ formatDate(c.created_at) }}</span>
                </div>
                <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ c.body }}</p>
                <div v-if="c.replies?.length" class="mt-3 space-y-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <div v-for="r in c.replies" :key="r.id" class="rounded-lg p-3" :class="r.from_author ? 'ml-6 bg-brand-50 dark:bg-brand-500/10' : 'mr-6 bg-slate-50 dark:bg-slate-800/50'">
                        <p class="text-xs font-medium text-slate-500">{{ r.author }} · {{ formatDate(r.created_at) }}</p>
                        <p class="whitespace-pre-line text-sm">{{ r.body }}</p>
                    </div>
                </div>
                <form class="mt-3 flex gap-2" @submit.prevent="reply(c)">
                    <input v-model="replies[c.id]" class="input" :placeholder="c.anonymous ? 'Reply anonymously…' : 'Reply…'" />
                    <button class="btn-secondary" :disabled="!(replies[c.id] ?? '').trim()"><Send class="size-4" /></button>
                </form>
            </div>
        </div>

        <Modal :open="open" title="Comment / suggestion" @close="open = false">
            <div class="space-y-4">
                <div>
                    <label class="label">Category</label>
                    <select v-model="form.category" class="input"><option value="">— Select —</option><option v-for="[k, v] in Object.entries(categories)" :key="k" :value="k">{{ v }}</option></select>
                    <p v-if="errors.category" class="field-error">{{ errors.category }}</p>
                </div>
                <div><label class="label">Your comment</label><textarea v-model="form.body" rows="4" class="input" /><p v-if="errors.body" class="field-error">{{ errors.body }}</p></div>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.anonymous" type="checkbox" class="rounded" /> Submit anonymously</label>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="open = false">Cancel</button>
                <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Submitting…' : 'Submit' }}</button>
            </template>
        </Modal>
    </div>
</template>
