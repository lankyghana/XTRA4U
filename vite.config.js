import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // Force dev server to bind to IPv4 localhost so CSP entries are valid
        host: '127.0.0.1',
        // Ensure HMR uses the IPv4 host when running in dev
        hmr: {
            host: '127.0.0.1'
        }
    }
});
