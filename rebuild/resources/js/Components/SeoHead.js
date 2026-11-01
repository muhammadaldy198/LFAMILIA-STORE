import { Head, usePage } from '@inertiajs/vue3';
import { h } from 'vue';

// The server supplies the same SEO object used on Inertia navigations.
export default {
    name: 'SeoHead',
    setup() {
        const page = usePage();

        return () => {
            const seo = page.props.seo;
            if (!seo) return null;

            const tags = [
                h('meta', { 'head-key': 'seo-description', name: 'description', content: seo.description }),
                h('meta', { 'head-key': 'seo-robots', name: 'robots', content: 'index,follow' }),
                h('link', { 'head-key': 'seo-canonical', rel: 'canonical', href: seo.canonical }),
                h('meta', { 'head-key': 'seo-site', property: 'og:site_name', content: seo.siteName }),
                h('meta', { 'head-key': 'seo-locale', property: 'og:locale', content: 'id_ID' }),
                h('meta', { 'head-key': 'seo-type', property: 'og:type', content: seo.type }),
                h('meta', { 'head-key': 'seo-og-title', property: 'og:title', content: seo.title }),
                h('meta', { 'head-key': 'seo-og-description', property: 'og:description', content: seo.description }),
                h('meta', { 'head-key': 'seo-og-url', property: 'og:url', content: seo.canonical }),
                h('meta', { 'head-key': 'seo-twitter-card', name: 'twitter:card', content: seo.image ? 'summary_large_image' : 'summary' }),
                h('meta', { 'head-key': 'seo-twitter-title', name: 'twitter:title', content: seo.title }),
                h('meta', { 'head-key': 'seo-twitter-description', name: 'twitter:description', content: seo.description }),
            ];
            if (seo.image) {
                tags.push(h('meta', { 'head-key': 'seo-og-image', property: 'og:image', content: seo.image }));
            }
            if (seo.structuredData) {
                tags.push(h('script', { 'head-key': 'seo-jsonld', type: 'application/ld+json' },
                    JSON.stringify(seo.structuredData).replace(/</g, '\\u003c')));
            }
            // Head's title prop escapes user-controlled text before serialization.
            return h(Head, { title: '@@LFSEO@@' + seo.title }, { default: () => tags });
        };
    },
};
