import { useIntervalFn } from '@vueuse/core';
import type { MaybeRefOrGetter } from 'vue';
import { computed, ref, toValue } from 'vue';
import { languageTag } from '@/lib/i18n';

/**
 * Largest first. Days, weeks and months depend on the calendar and the time
 * zone, so they are measured between ZonedDateTimes rather than derived from
 * a number of seconds.
 */
const UNITS = [
    'year',
    'month',
    'week',
    'day',
    'hour',
    'minute',
] as const satisfies readonly (Temporal.DateUnit | Temporal.TimeUnit)[];

const TICK_MS = 30_000;

/**
 * "il y a 4 minutes" / "4 minutes ago" in the active language for an
 * ISO 8601 timestamp, refreshed every 30 seconds. Under a minute reads as
 * "now".
 *
 * Relies on the global Temporal API; browsers without it receive the
 * polyfill on demand (see `ensureTemporal`).
 */
export function useRelativeTime(
    timestamp: MaybeRefOrGetter<string | null | undefined>,
) {
    const tick = ref(0);

    useIntervalFn(() => tick.value++, TICK_MS);

    return computed(() => {
        const value = toValue(timestamp);

        if (!value) {
            return '';
        }

        void tick.value;

        const timeZone = Temporal.Now.timeZoneId();
        const now = Temporal.Now.zonedDateTimeISO(timeZone);
        const then = Temporal.Instant.from(value).toZonedDateTimeISO(timeZone);
        const elapsed = now.until(then, { largestUnit: 'year' });
        const format = new Intl.RelativeTimeFormat(languageTag(), {
            numeric: 'auto',
        });

        for (const unit of UNITS) {
            const amount = Math.round(elapsed.total({ unit, relativeTo: now }));

            if (amount !== 0) {
                return format.format(amount, unit);
            }
        }

        return format.format(0, 'second');
    });
}
