<script setup>
/** Company details form fields (used for create and edit). */
defineProps({ errors: { type: Object, default: () => ({}) }, showCode: Boolean, showAdmin: Boolean });
const emit = defineEmits(['submit']);
const form = defineModel({ type: Object, required: true });
</script>

<template>
    <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="emit('submit')">
        <div class="sm:col-span-2">
            <label class="label">Company name <span class="text-rose-500">*</span></label>
            <input v-model="form.name" class="input" maxlength="190" />
            <p v-if="errors.name" class="field-error">{{ errors.name }}</p>
        </div>
        <div v-if="showCode">
            <label class="label">Short code <span class="text-rose-500">*</span></label>
            <input v-model="form.code" class="input uppercase" maxlength="20" placeholder="e.g. SFS" />
            <p v-if="errors.code" class="field-error">{{ errors.code }}</p>
        </div>
        <div>
            <label class="label">Brand colour</label>
            <div class="flex items-center gap-2">
                <input v-model="form.brand_color" type="color" class="h-10 w-12 cursor-pointer rounded-lg border border-slate-300 bg-transparent dark:border-slate-700" />
                <input v-model="form.brand_color" class="input" maxlength="7" placeholder="#4f46e5" />
            </div>
            <p v-if="errors.brand_color" class="field-error">{{ errors.brand_color }}</p>
        </div>
        <div>
            <label class="label">Email</label>
            <input v-model="form.email" type="email" class="input" />
            <p v-if="errors.email" class="field-error">{{ errors.email }}</p>
        </div>
        <div>
            <label class="label">Phone</label>
            <input v-model="form.phone" type="tel" class="input" placeholder="e.g. 0712345678" />
            <p v-if="errors.phone" class="field-error">{{ errors.phone }}</p>
        </div>
        <div>
            <label class="label">Location</label>
            <input v-model="form.location" class="input" placeholder="e.g. Westlands, Nairobi" />
            <p v-if="errors.location" class="field-error">{{ errors.location }}</p>
        </div>
        <div>
            <label class="label">Postal address</label>
            <input v-model="form.address" class="input" />
            <p v-if="errors.address" class="field-error">{{ errors.address }}</p>
        </div>
        <div>
            <label class="label">Tax (KRA) PIN</label>
            <input v-model="form.tax_pin" class="input uppercase" maxlength="11" placeholder="e.g. P051234567X" />
            <p v-if="errors.tax_pin" class="field-error">{{ errors.tax_pin }}</p>
        </div>
        <div>
            <label class="label">Incorporation number</label>
            <input v-model="form.incorporation_number" class="input" maxlength="50" />
            <p v-if="errors.incorporation_number" class="field-error">{{ errors.incorporation_number }}</p>
        </div>
        <div>
            <label class="label">Incorporation date</label>
            <input v-model="form.incorporation_date" type="date" class="input" />
            <p v-if="errors.incorporation_date" class="field-error">{{ errors.incorporation_date }}</p>
        </div>
        <template v-if="showAdmin">
            <div class="border-t border-slate-100 pt-4 sm:col-span-2 dark:border-slate-800">
                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Company administrator <span class="font-normal text-slate-400">(optional)</span></p>
                <p class="text-xs text-slate-500">We will create an HR Administrator account and email an activation link.</p>
            </div>
            <div>
                <label class="label">Admin name</label>
                <input v-model="form.admin_name" class="input" />
                <p v-if="errors.admin_name" class="field-error">{{ errors.admin_name }}</p>
            </div>
            <div>
                <label class="label">Admin email</label>
                <input v-model="form.admin_email" type="email" class="input" />
                <p v-if="errors.admin_email" class="field-error">{{ errors.admin_email }}</p>
            </div>
        </template>
        <button type="submit" class="hidden" />
    </form>
</template>
