<script setup>
import { ref, reactive, computed } from 'vue';
import api, { fieldErrors } from '@/lib/api';
import PasswordInput from '@/components/ui/PasswordInput.vue';
import PasswordStrength from '@/components/ui/PasswordStrength.vue';
import Spinner from '@/components/ui/Spinner.vue';

const emit = defineEmits(['changed']);
const form = reactive({ current_password: '', password: '', password_confirmation: '' });
const errors = ref({});
const loading = ref(false);
const mismatch = computed(() => form.password_confirmation && form.password !== form.password_confirmation);

async function submit() {
    errors.value = {};
    loading.value = true;
    try {
        const { data } = await api.put('/auth/password', form);
        Object.assign(form, { current_password: '', password: '', password_confirmation: '' });
        emit('changed', data.message);
    } catch (e) {
        errors.value = fieldErrors(e);
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <div>
            <label class="label">Current password</label>
            <PasswordInput v-model="form.current_password" :error="errors.current_password" />
        </div>
        <div>
            <label class="label">New password</label>
            <PasswordInput v-model="form.password" autocomplete="new-password" :error="errors.password" />
            <PasswordStrength :password="form.password" />
        </div>
        <div>
            <label class="label">Confirm new password</label>
            <PasswordInput v-model="form.password_confirmation" autocomplete="new-password" :error="mismatch ? 'Passwords do not match.' : null" />
        </div>
        <button class="btn-primary" :disabled="loading || !form.current_password || !form.password || mismatch">
            <Spinner v-if="loading" /> Update password
        </button>
    </form>
</template>
