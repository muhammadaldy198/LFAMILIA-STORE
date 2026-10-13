import { createApp, h, watch } from 'vue';
import { createInertiaApp, usePage } from '@inertiajs/vue3';

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
        createApp({
            setup() {
                const page = usePage();
                watch(() => page.props.theme, colors => {
                    for (const [key, value] of Object.entries(colors || {})) {
                        if (/^[a-z-]+$/.test(key) && /^#[0-9A-Fa-f]{6}$/.test(value)) document.documentElement.style.setProperty('--lf-color-' + key, value);
                    }
                    const primary = colors?.primary;
                    if (/^#[0-9A-Fa-f]{6}$/.test(primary || '')) document.documentElement.style.setProperty('--lf-primary-rgb', [1,3,5].map(start => parseInt(primary.slice(start,start+2),16)).join(','));
                }, {immediate:true});
                return () => h(App, props);
            },
        })
            .use(plugin)
            .mount(el);
    },
});
