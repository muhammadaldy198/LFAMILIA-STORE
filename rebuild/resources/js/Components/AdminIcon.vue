<script setup>
defineProps({ name: String });
const paths = {
 dashboard:'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
 orders:'M6 3h12v18H6z M9 7h6 M9 11h6 M9 15h4',
 catalog:'m12 3 9 5-9 5-9-5 9-5 M3 8v9l9 5 9-5V8 M12 13v9',
 content:'M3 4h18v16H3z m0 13 5-5 4 4 4-6 5 7 M8 8h.01',
 providers:'M4 7h16v10H4z M8 3v4 M16 3v4 M8 17v4 M16 17v4 M8 11h8',
 nickname:'M5 5h14v14H5z M8 10h3 M9.5 8.5v3 M15 10h.01 M17 12h.01',
 payments:'M3 5h18v14H3z M3 9h18 M7 15h4',
 customers:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M16 3a4 4 0 0 1 0 8',
 vouchers:'M3 7h18v4a2 2 0 0 0 0 4v4H3v-4a2 2 0 0 0 0-4V7 M13 7v12',
 support:'M4 13v-1a8 8 0 0 1 16 0v1 M3 11h4v7H3z M17 11h4v7h-4z M19 18v3h-6',
 reports:'M4 20V10 M10 20V4 M16 20v-7 M22 20H2',
 access:'M12 3 3 7v5c0 5 9 9 9 9s9-4 9-9V7l-9-4 M9 12l2 2 4-4',
 settings:'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M12 2v3 M12 19v3 M2 12h3 M19 12h3 M5 5l2 2 M17 17l2 2 M5 19l2-2 M17 7l2-2',
 integrations:'M8 3v5 M16 3v5 M5 8h14v4a7 7 0 0 1-14 0V8 M12 19v3',
 health:'M2 12h5l3-8 4 16 3-8h5',
 audit:'M6 3h12v18H6z M9 7h6 M9 11h6 M9 15h6',
 fulfillment:'M4 4h16v16H4z M8 12l3 3 5-6',
 search:'M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14 M15 15l6 6',
 bell:'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4',
 close:'M6 6l12 12 M6 18 18 6',
 menu:'M3 6h18 M3 12h18 M3 18h18',
 logout:'M9 4H4v16h5 M14 8l4 4-4 4 M8 12h10',
 store:'M3 9h18 M5 9v12h14V9 M3 9l2-6h14l2 6 M9 21v-7h6v7',
};
</script>
<template><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="paths[name] || paths.dashboard" /></svg></template>
