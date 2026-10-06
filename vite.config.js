import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    server: {
        /*
         * Pinned, rather than left on Vite's default 5173.
         *
         * strictPort is the half that makes it a pin: without it Vite takes the next free port when
         * 5177 is busy, writes THAT into public/hot, and the dev server you are actually looking at
         * is whichever one wrote the file last. A second `npm run dev` in a forgotten tab then serves
         * the app its assets. Failing to start is the honest outcome - it says which port is taken
         * instead of quietly moving.
         */
        port: 5177,
        strictPort: true,
    },
    plugins: [
        laravel({
            input: 'resources/js/app.js',
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
});
