// Customer-facing ticket formatting only. The ticket status used by business logic stays unchanged.
const statuses = {
    OPEN: 'Terbuka',
    IN_PROGRESS: 'Sedang ditangani',
    RESOLVED: 'Selesai',
    CLOSED: 'Ditutup',
};

export function ticketStatusLabel(status) {
    return statuses[status] || String(status || '–');
}

export function formatTicketDate(value) {
    if (!value) return '–';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '–';
    const parts = new Intl.DateTimeFormat('id-ID', {
        timeZone: 'Asia/Jakarta',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);
    const part = type => parts.find(item => item.type === type)?.value || '';
    return `${part('day')} ${part('month')} ${part('year')}, ${part('hour')}.${part('minute')}`;
}
