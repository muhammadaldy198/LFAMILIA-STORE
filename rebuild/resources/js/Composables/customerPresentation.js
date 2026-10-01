import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useCustomerPresentation() {
    const page = usePage();
    const presentation = computed(() => page.props.storefront?.presentation || {});
    const customerText = (key, fallback) => {
        const value = presentation.value.text?.[key];
        return typeof value === 'string' && value.trim() ? value : fallback;
    };
    const sectionEnabled = key => presentation.value.sections?.[key] !== false;
    const presentationStyle = computed(() => {
        const style = {};
        for (const viewport of ['mobile', 'desktop']) {
            const values = presentation.value.typography?.[viewport] || {};
            for (const [field, value] of Object.entries(values)) {
                style['--lf-config-' + viewport + '-' + field] = String(value) + (field.endsWith('Weight') ? '' : 'px');
            }
        }
        return style;
    });
    return { customerText, sectionEnabled, presentationStyle };
}
