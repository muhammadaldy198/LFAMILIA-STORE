import { createApp, h, watch } from 'vue';
import { createInertiaApp, usePage } from '@inertiajs/vue3';

const pages = import.meta.glob('./Pages/**/*.vue');

createInertiaApp({
    title: (title) => title?.startsWith('@@LFSEO@@') ? title.slice('@@LFSEO@@'.length) : (title ? `${title} — LFAMILIA STORE` : 'LFAMILIA STORE'),
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
                // Inertia keeps the initial document head across customer/admin navigation.
                // Disable the independent admin entry before showing customer pages.
                watch(() => page.url, url => {
                    const adminRoute = /^\/admin(?:\/|$|\?)/.test(String(url || ''));
                    const styles = [...document.querySelectorAll('link[rel="stylesheet"]')]
                        .filter(link => /(?:\/assets\/admin-[^/]+\.css|\/resources\/css\/admin\.css)(?:\?|$)/.test(link.href));
                    for (const stylesheet of styles) stylesheet.disabled = !adminRoute;
                    if (adminRoute && !styles.length) window.location.assign(url);
                }, {immediate:true});
                watch(() => page.props?.theme, colors => {
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
