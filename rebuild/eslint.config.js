import js from '@eslint/js';
import globals from 'globals';
import pluginVue from 'eslint-plugin-vue';

export default [
    {
        ignores: [
            'node_modules/**',
            'public/build/**',
            'storage/**',
            'vendor/**',
        ],
    },
    js.configs.recommended,
    ...pluginVue.configs['flat/essential'],
    {
        files: ['resources/js/**/*.{js,vue}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: globals.browser,
        },
        rules: {
            'no-duplicate-imports': 'error',
            'no-unreachable': 'error',
            'no-unused-vars': [
                'error',
                {
                    args: 'after-used',
                    argsIgnorePattern: '^_',
                    caughtErrors: 'all',
                    caughtErrorsIgnorePattern: '^_',
                    varsIgnorePattern: '^_',
                },
            ],
            'vue/no-unused-vars': 'error',
            'vue/require-explicit-emits': 'error',
        },
    },
    {
        files: ['vite.config.js', 'eslint.config.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: globals.nodeBuiltin,
        },
    },
];
