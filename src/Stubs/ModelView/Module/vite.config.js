import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

export default defineConfig({

    plugins: [vue()],

    resolve: {
        alias: {
            '@module': path.resolve(import.meta.dirname, './'),
            '@moduleModels': path.resolve(import.meta.dirname, './src/models'),
            '@moduleComponents': path.resolve(import.meta.dirname, './src/components'),
        },
    },

    build: {
        lib: {
            entry: path.resolve(import.meta.dirname, 'index.js'),
            formats: ['es'],
            fileName: 'index',
        },
        rollupOptions: {
            // Todo lo que aporta el anfitrion se mantiene externo para no
            // duplicar Vue ni el router en el bundle final.
            external: [
                'vue',
                'vue-router',
                'pinia',
                /^innoboxrr-/,
            ],
        },
    },

})
