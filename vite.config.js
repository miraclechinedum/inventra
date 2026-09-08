import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Inter', {
                    weights: [400, 500, 600, 700],
                    optimizedFallbacks: false,
                }),
                // Display face for the public homepage only. Self-hosted through the
                // same pipeline as Inter so there is no external runtime request —
                // an external font CDN would be blocked by the nonce-based CSP.
                bunny('Space Grotesk', {
                    // Only the weights the homepage display styles actually ask for.
                    weights: [600, 700],
                    // 700 carries the hero headline, so it is the only one worth
                    // preloading; 600 is first used well below the fold.
                    preload: [{ weight: 700 }],
                    optimizedFallbacks: false,
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
