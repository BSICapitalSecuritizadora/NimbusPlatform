import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament/admin/theme.css',
                'resources/js/app.js',
                'resources/js/app.ts',
            ],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
