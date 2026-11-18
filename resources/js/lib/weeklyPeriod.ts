/** Suggest the current seven-day period; weekdays use ISO Monday=1 through Sunday=7. */
export function weeklyPeriodStart(timezone: string, closeWeekday: number | null, now = new Date()): string {
    const parts = new Intl.DateTimeFormat('en', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
    const part = (name: string) => Number(parts.find(value => value.type === name)?.value);
    const day = new Date(Date.UTC(part('year'), part('month') - 1, part('day')));
    if (closeWeekday !== null && Number.isInteger(closeWeekday) && closeWeekday >= 1 && closeWeekday <= 7) {
        const currentWeekday = day.getUTCDay() || 7;
        const firstWeekday = closeWeekday % 7 + 1;
        day.setUTCDate(day.getUTCDate() - (currentWeekday - firstWeekday + 7) % 7);
    }
    return day.toISOString().slice(0, 10);
}
