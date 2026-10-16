/** Database timestamps without an offset are UTC, never browser-local time. */
export function storedDate(source: string): Date | null {
    let value = source.trim().replace(' ', 'T');
    if (!value) return null;
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) value += 'T00:00:00';
    // PostgreSQL can return +00, +0000, or +00:00.
    value = value.replace(/([+-]\d{2})$/, '$1:00').replace(/([+-]\d{2})(\d{2})$/, '$1:$2');
    if (!/(?:Z|[+-]\d{2}:\d{2})$/i.test(value)) value += 'Z';
    const date = new Date(value);
    return Number.isFinite(date.getTime()) ? date : null;
}

export function localInput(timeZone: string, source?: string): string {
    const date = source === undefined ? new Date() : storedDate(source);
    if (!date) return '';
    const settings: Intl.DateTimeFormatOptions = {
        timeZone, year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    };
    let formatter: Intl.DateTimeFormat;
    try { formatter = new Intl.DateTimeFormat('en-CA', settings); }
    catch { formatter = new Intl.DateTimeFormat('en-CA', { ...settings, timeZone: 'UTC' }); }
    const parts = Object.fromEntries(formatter.formatToParts(date).map(part => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

export function displayDate(timeZone: string, source: string): string {
    return localInput(timeZone, source).replace('T', ' ') || '—';
}
