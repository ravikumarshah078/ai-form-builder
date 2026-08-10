import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/scss/app.scss', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                // Bootstrap 5.3 still uses @import and legacy colour helpers,
                // which Dart Sass 1.102 flags. Nothing we can fix on our side
                // until Bootstrap 6, so keep the build output readable.
                silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'mixed-decls'],
                quietDeps: true,
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
