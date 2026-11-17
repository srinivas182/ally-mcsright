import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { fileURLToPath } from 'node:url';
import { defineConfig, lazyPlugins } from 'vite-plus';

// Vitest sets VITEST. Component tests need only React: the Laravel, Wayfinder and font
// plugins start PHP, fetch fonts, and refuse to run when CI=true.
const isVitest = process.env.VITEST !== undefined;

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    plugins: lazyPlugins(() =>
        isVitest
            ? [react()]
            : [
                  laravel({
                      input: ['resources/css/app.css', 'resources/js/app.tsx'],
                      refresh: true,
                      fonts: [
                          bunny('Instrument Sans', {
                              weights: [400, 500, 600],
                          }),
                      ],
                  }),
                  inertia(),
                  react(),
                  babel({
                      presets: [reactCompilerPreset()],
                  }),
                  tailwindcss(),
                  wayfinder({
                      formVariants: true,
                  }),
              ],
    ),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.{ts,tsx}'],
        setupFiles: ['resources/js/test/setup.ts'],
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        // AX2 (specs/core/13): accessibility rules for every page (WCAG 2.2 AA).
        plugins: ['typescript', 'unicorn', 'oxc', 'jsx-a11y'],
        rules: {
            'jsx-a11y/alt-text': 'error',
            'jsx-a11y/anchor-has-content': 'error',
            'jsx-a11y/anchor-is-valid': 'error',
            'jsx-a11y/aria-props': 'error',
            'jsx-a11y/aria-proptypes': 'error',
            'jsx-a11y/aria-role': 'error',
            'jsx-a11y/aria-unsupported-elements': 'error',
            'jsx-a11y/autocomplete-valid': 'error',
            'jsx-a11y/click-events-have-key-events': 'error',
            'jsx-a11y/heading-has-content': 'error',
            'jsx-a11y/html-has-lang': 'error',
            'jsx-a11y/iframe-has-title': 'error',
            'jsx-a11y/img-redundant-alt': 'error',
            'jsx-a11y/label-has-associated-control': [
                'error',
                {
                    controlComponents: [
                        'Input',
                        'Textarea',
                        'Select',
                        'Checkbox',
                        'Switch',
                        'Combobox',
                        'MoneyInput',
                        'NumberInput',
                        'PercentInput',
                        'DateRangeInput',
                        'RadioGroup',
                        'InputOTP',
                    ],
                    depth: 4,
                },
            ],
            'jsx-a11y/media-has-caption': 'error',
            'jsx-a11y/mouse-events-have-key-events': 'error',
            'jsx-a11y/no-access-key': 'error',
            'jsx-a11y/no-autofocus': 'error',
            'jsx-a11y/no-distracting-elements': 'error',
            'jsx-a11y/no-noninteractive-tabindex': 'error',
            'jsx-a11y/no-redundant-roles': 'error',
            'jsx-a11y/no-static-element-interactions': 'error',
            'jsx-a11y/role-has-required-aria-props': 'error',
            'jsx-a11y/role-supports-aria-props': 'error',
            'jsx-a11y/scope': 'error',
            'jsx-a11y/tabindex-no-positive': 'error',
            // Flags datalist options and empty table cells; empty header cells get screen-reader text instead.
            'jsx-a11y/control-has-associated-label': 'off',
            'jsx-a11y/no-noninteractive-element-interactions': 'error',
            'jsx-a11y/no-noninteractive-element-to-interactive-role': 'error',
            // role="status" on a div is announced well by every screen reader; <output> is not needed.
            'jsx-a11y/prefer-tag-over-role': 'off',
        },
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'resources/css/tokens.css',
            // AI prompts are reviewed exactly as written; reformatting would change them.
            'modules/*/prompts/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
