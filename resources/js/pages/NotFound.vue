<script setup>
import { Compass } from 'lucide-vue-next';
</script>

<template>
    <div class="flex min-h-[60vh] flex-col items-center justify-center text-center">
        <div class="mb-4 rounded-full bg-slate-100 p-4 text-slate-400 dark:bg-slate-800"><Compass class="size-8" /></div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">This page is on its way</h1>
        <p class="mt-2 max-w-sm text-sm text-slate-500">The page you're looking for doesn't exist yet or has moved. Modules marked “Soon” are still being built.</p>
        <RouterLink to="/" class="btn-primary mt-6">Back to dashboard</RouterLink>
    </div>
</template>
