<script>
import { Comment, Fragment, Text, defineComponent, h, onMounted, onUnmounted, ref } from 'vue';
import { Table } from './ui/table';

// Use the same slots and action handlers for either representation. Each workspace
// chooses its priority columns; secondary data stays available in a disclosure.
const flatten = nodes => (nodes || []).flatMap(node => node.type === Fragment
    ? flatten(node.children) : node.type === Comment ? [] : [node]);
const children = node => flatten(Array.isArray(node.children) ? node.children : node.children?.default?.());
const text = node => node.type === Text || typeof node.children === 'string'
    ? String(node.children || '') : children(node).map(text).join(' ');

export default defineComponent({
    inheritAttrs: false,
    props: { mobileColumns: { type: Array, required: true } },
    setup(props, { slots, attrs }) {
        const mobile = ref(false);
        let media;
        const update = () => { mobile.value = media.matches; };
        onMounted(() => {
            media = window.matchMedia('(max-width: 767px)');
            update();
            media.addEventListener('change', update);
        });
        onUnmounted(() => media?.removeEventListener('change', update));
        return () => {
            if (!mobile.value) return h(Table, attrs, slots);
            const sections = flatten(slots.default?.());
            const headings = children(children(sections[0])[0] || { children: [] }).map(text);
            const rows = sections.slice(1).flatMap(children);
            const field = (cell, index, identity = false) => h('div', {
                class: ['lf-admin-record-field', identity && 'lf-admin-record-identity'], key: index,
            }, [
                !identity && h('span', { class: 'lf-admin-record-label' }, headings[index]),
                h('div', { class: 'lf-admin-record-value' }, children(cell)),
            ]);
            return h('div', { class: 'lf-admin-record-list' }, rows.map((row, rowIndex) => {
                const cells = children(row);
                if (cells.length === 1 && Number(cells[0].props?.colspan) > 1) {
                    return h('div', { class: 'lf-admin-empty', key: row.key ?? rowIndex }, children(cells[0]));
                }
                const priority = props.mobileColumns.filter(index => cells[index]);
                const secondary = cells.map((cell, index) => ({ cell, index })).filter(({ index }) => !priority.includes(index));
                return h('article', { class: 'lf-admin-record', key: row.key ?? rowIndex }, [
                    h('div', { class: 'lf-admin-record-main' }, priority.map((index, position) => field(cells[index], index, position === 0))),
                    secondary.length > 0 && h('details', { class: 'lf-admin-record-details' }, [
                        h('summary', 'Detail'),
                        h('div', { class: 'lf-admin-record-main' }, secondary.map(({ cell, index }) => field(cell, index))),
                    ]),
                ]);
            }));
        };
    },
});
</script>
