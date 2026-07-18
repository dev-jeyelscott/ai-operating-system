import { defineConfig } from 'vite';

/**
 * Configures Vite for Laravel Sail running through Docker and WSL2.
 */
export default defineConfig({
    // Keep your existing plugins and configuration here.

    server: {
        // Listen on all container interfaces so Docker can expose port 5173.
        host: '0.0.0.0',

        // Use a fixed port instead of silently switching to 5174 or another port.
        port: 5173,
        strictPort: true,

        // Generate development asset URLs that Windows Chrome can access.
        origin: 'http://localhost:5173',

        // Ensure the browser connects to HMR through the Docker-published port.
        hmr: {
            host: 'localhost',
        },

        // Useful when editing WSL/Docker-mounted files from Windows applications.
        watch: {
            usePolling: true,
        },
    },
});
