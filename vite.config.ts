import vue from '@vitejs/plugin-vue';
import autoprefixer from 'autoprefixer';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from 'tailwindcss';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    build: {
        rollupOptions: {
            output: {
                // Framework code changes rarely: its own long-cached file, separate from app code.
                manualChunks(id) {
                    if (!id.includes('node_modules')) return;
                    if (/node_modules\/(@vue|vue|@inertiajs|axios|qs|ziggy-js)\//.test(id)) return 'vendor';
                    if (/node_modules\/(radix-vue|@floating-ui|@internationalized|@tanstack)\//.test(id)) return 'ui';
                },
            },
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    css: {
        postcss: {
            plugins: [tailwindcss, autoprefixer],
        },
    },
});
