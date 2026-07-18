import { fileURLToPath, URL } from 'node:url';
import react from '@vitejs/plugin-react';
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
        /**
         * Restrict Vitest to frontend unit and component tests.
         *
         * Playwright specifications live under tests/e2e and must only be
         * collected by the Playwright test runner.
         */
        include: [
            'resources/js/**/*.{test,spec}.{ts,tsx}',
        ],

        /**
         * Provide a browser-like DOM environment for React component tests.
         */
        environment: 'jsdom',

        /**
         * Register shared Testing Library and DOM assertion configuration.
         */
        setupFiles: ['./resources/js/tests/setup.ts'],

        /**
         * Allow Vitest APIs such as describe, test, expect, and vi globally.
         */
        globals: true,

        /**
         * Process imported CSS through the Vite pipeline during tests.
         */
        css: true,

        /**
         * Restore mocks after each test to prevent cross-test contamination.
         */
        restoreMocks: true,
    },
});
