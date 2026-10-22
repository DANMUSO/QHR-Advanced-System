<script setup>
import { ref, onMounted } from 'vue';
import { MessageCircle } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const comments = ref([]);
const paginator = ref(null);
const summary = ref({});
const categories = ref({});
const loading = ref(false);
const replyModal = ref({ open: false, comment: null });
const replyBody = ref('');
const replying = ref(false);

const columns = [
    { key: 'category_label', label: 'Category' },
    { key: 'employee', label: 'Employee', format: (v, r) => r.anonymous ? 'Anonymous' : (r.employee?.name ?? '—') },
    { key: 'body', label: 'Comment', class: 'max-w-xs truncate' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Date', format: v => v?.slice(0, 10) },
];

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await api.get('/engagement/comments', { params: { page } });
        comments.value = data.data.data ?? data.data;
        paginator.value = data.data.current_page ? { current_page: data.data.current_page, last_page: data.data.last_page, total: data.data.total, from: data.data.from, to: data.data.to } : null;
        summary.value = data.summary;
        categories.value = data.categories;
    } finally {
        loading.value = false;
    }
}

async function reply() {
    replying.value = true;
    try {
        await api.post(`/engagement/comments/${replyModal.value.comment.id}/reply`, { body: replyBody.value });
        swal.toast('Reply sent.');
        replyModal.value.open = false;
        await load();
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        replying.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Staff Engagement" />

        <!-- Category summary -->
        <div class="mb-4 flex flex-wrap gap-2">
            <div v-for="[cat, count] in Object.entries(summary)" :key="cat" class="card flex items-center gap-2 px-3 py-2 text-sm">
                <span class="font-medium">{{ categories[cat] ?? cat }}</span>
                <Badge color="blue">{{ count }}</Badge>
            </div>
        </div>

        <DataTable :columns="columns" :rows="comments" :loading="loading" :paginator="paginator" clickable @page="load" @row-click="r => { replyModal = { open: true, comment: r }; replyBody = ''; }">
            <template #cell-status="{ row }">
                <Badge :color="row.status === 'replied' ? 'green' : 'yellow'">{{ row.status }}</Badge>
            </template>
        </DataTable>

        <Modal :open="replyModal.open" title="Comment & Reply" size="lg" @close="replyModal.open = false">
            <div v-if="replyModal.comment" class="space-y-4">
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-slate-800/50">
                    <p class="text-xs text-slate-400 mb-1">{{ replyModal.comment.category_label }} · {{ replyModal.comment.created_at?.slice(0,10) }}</p>
                    <p>{{ replyModal.comment.body }}</p>
                </div>
                <div v-if="replyModal.comment.replies?.length" class="space-y-2">
                    <div v-for="r in replyModal.comment.replies" :key="r.id" class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                        <p class="text-xs text-slate-400">{{ r.author }} · {{ r.created_at?.slice(0,10) }}</p>
                        <p class="text-sm">{{ r.body }}</p>
                    </div>
                </div>
                <div>
                    <label class="label">Reply</label>
                    <textarea v-model="replyBody" rows="3" class="input" />
                </div>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="replyModal.open = false">Close</button>
                <button class="btn-primary" :disabled="replying || !replyBody" @click="reply">{{ replying ? 'Sending…' : 'Send Reply' }}</button>
            </template>
        </Modal>
    </div>
</template>
