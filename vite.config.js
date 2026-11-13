import fs from 'node:fs';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/finance-preview.js',
                'resources/js/filament/schemas/components/fiscal-document-totals.js',
                'resources/css/filament/admin/theme.css',
                'resources/css/site.css',
                'resources/js/site.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
        // `vite` writes public/hot so Laravel loads assets from the dev server, and deletes it on
        // exit. When the dev server dies without exiting cleanly (killed, crashed, terminal closed)
        // the file stays, and every page keeps asking a server that is gone for its CSS. A build
        // means "use the built files", so it removes the leftover file.
        {
            name: 'remove-stale-hot-file',
            apply: 'build',
            buildStart() {
                fs.rmSync('public/hot', { force: true });
            },
        },
    ],
    build: {
        rolldownOptions: { preserveEntrySignatures: 'exports-only' },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
