import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

const pages = import.meta.glob('./Pages/**/*.vue');

createInertiaApp({
    title: (title) => title ? `${title} — LFAMILIA STORE` : 'LFAMILIA STORE',
    resolve: (name) => {
        const page = pages[`./Pages/${name}.vue`];

        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        return page();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
