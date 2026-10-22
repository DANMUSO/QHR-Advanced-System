import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: { transformAssetUrls: { base: null, includeAbsolute: false } },
        }),
        tailwindcss(),
    ],
    // Emit chunk/preload URLs relative to the importing file so the build works from a sub-folder
    // (e.g. http://localhost/HR-ADVANCED%20APP/public under WAMP) as well as from a domain root.
    experimental: {
        renderBuiltUrl: (filename, { hostType }) => {
            if (hostType === 'js') return { relative: true };
            return { relative: true };
        },
    },
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
