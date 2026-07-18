import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],

    resolve: {
        alias: {
            '@': fileURLToPath(
                new URL('./resources/js', import.meta.url),
            ),
        },
    },

    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./resources/js/tests/setup.ts'],
        css: true,
        restoreMocks: true,
    },
});
