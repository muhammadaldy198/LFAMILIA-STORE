import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import globals from 'globals';

export default [
  {
    ignores: ['public/build/**', 'node_modules/**'],
  },
  js.configs.recommended,
  ...pluginVue.configs['flat/essential'],
  {
    files: ['resources/js/**/*.{js,vue}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        ...globals.browser,
      },
    },
    rules: {
      // Inertia page names and shadcn-style primitives intentionally use single-word filenames.
      'vue/multi-word-component-names': 'off',
      // Existing shadcn-style wrapper components intentionally receive v-html through attr fallthrough.
      'vue/no-v-text-v-html-on-component': 'off',
      'no-empty': ['error', { allowEmptyCatch: true }],
      'no-duplicate-imports': 'error',
      'no-unused-vars': ['error', {
        argsIgnorePattern: '^_',
        caughtErrorsIgnorePattern: '^_',
        varsIgnorePattern: '^_',
      }],
    },
  },
];
