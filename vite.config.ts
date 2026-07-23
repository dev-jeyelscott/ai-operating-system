import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

/**
 * Configures the Laravel, Inertia, React, Tailwind CSS, and Wayfinder
 * frontend build for local Docker/WSL development and production builds.
 */
export default defineConfig({
    plugins: [
        /**
         * Connect Vite to Laravel and define the application entry points.
         *
         * Without this plugin, Vite treats the project as a standalone
         * frontend application and searches for a root index.html file.
         */
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
            ],
            refresh: true,

            /**
             * Generate the Instrument Sans font assets consumed by Laravel's
             * @fonts Blade directive.
             */
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),

        /**
         * Enables Inertia-specific Vite behavior and page resolution.
         */
        inertia(),

        /**
         * Compiles React and TSX files.
         *
         * The React compiler plugin is already installed in this project.
         */
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),

        /**
         * Processes Tailwind CSS 4 through Vite.
         */
        tailwindcss(),

        /**
         * Generates type-safe Laravel route and action helpers.
         */
        wayfinder({
            formVariants: true,
        }),
    ],

    server: {
        /**
         * Listen on every container interface so Docker can publish Vite
         * to the Windows host.
         */
        host: '0.0.0.0',

        /**
         * Keep the development-server port deterministic.
         */
        port: 5173,
        strictPort: true,

        /**
         * Generate browser-accessible Vite asset URLs.
         *
         * This is the Vite server address, not the Laravel application
         * origin that is allowed by CORS.
         */
        origin: 'http://localhost:5173',

        /**
         * Allow the Laravel application to request scripts, styles,
         * React refresh modules, and the Vite HMR client.
         *
         * Keep this restricted to the application origin instead of
         * setting `cors: true`, which would expose source assets to
         * arbitrary websites.
         */
        cors: {
            origin: 'http://localhost',
        },

        /**
         * Route hot-module replacement through Docker Desktop's
         * published localhost port.
         */
        hmr: {
            host: 'localhost',
        },

        /**
         * Use polling because filesystem events may not propagate
         * consistently between Windows, WSL, and Docker.
         */
        watch: {
            usePolling: true,
        },
    },
});
