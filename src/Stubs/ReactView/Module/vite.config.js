import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'node:path'

export default defineConfig({

    plugins: [react()],

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
            // duplicar React ni el router en el bundle final.
            external: [
                'react',
                'react-dom',
                'react/jsx-runtime',
                'react-router-dom',
                'zustand',
                /^innoboxrr-/,
            ],
        },
    },

})
