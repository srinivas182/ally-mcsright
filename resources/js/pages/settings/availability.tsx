import { Head, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { Switch } from '@/components/ui/switch';

type Day = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';
type Range = [string, string];
type Props = {
    availability: {
        enabled: boolean;
        hours: Record<Day, Range[]>;
        slot_minutes: number;
        notice_hours: number;
    };
    timezone: string;
    next: string[];
};

const days: [Day, string][] = [
    ['mon', 'Monday'],
    ['tue', 'Tuesday'],
    ['wed', 'Wednesday'],
    ['thu', 'Thursday'],
    ['fri', 'Friday'],
    ['sat', 'Saturday'],
    ['sun', 'Sunday'],
];

/**
 * Settings → My availability: the weekly hours the website assistant may book calls, showings and listing
 * appointments in. Booked times become CRM tasks, so anything already on the agent's task list stays free.
 */
export default function Availability({ availability, timezone, next }: Props) {
    const form = useForm({ ...availability });
    const setDay = (day: Day, ranges: Range[]) =>
        form.setData('hours', { ...form.data.hours, [day]: ranges });

    return (
        <>
            <Head title="My availability" />
            <div className="grid max-w-3xl gap-6 p-4">
                <PageHeader
                    title="My availability"
                    description={`When website visitors can book a call, showing or listing appointment with you. Times are in ${timezone}.`}
                />
                <form
                    className="grid gap-5 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/settings/availability', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="flex items-center gap-3">
                        <Switch
                            checked={form.data.enabled}
                            onCheckedChange={(v) => form.setData('enabled', v)}
                        />
                        <span className="font-medium">
                            Let the website book time with me
                        </span>
                        {availability.enabled ? (
                            <Badge>On</Badge>
                        ) : (
                            <Badge variant="outline">Off</Badge>
                        )}
                    </label>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 font-medium">
                            Weekly hours
                        </legend>
                        {days.map(([key, label]) => {
                            const ranges = form.data.hours[key] ?? [];

                            return (
                                <div
                                    key={key}
                                    className="flex flex-wrap items-center gap-2 border-b py-2 last:border-0"
                                >
                                    <span className="w-24 font-medium">
                                        {label}
                                    </span>
                                    {ranges.length === 0 && (
                                        <span className="text-muted-foreground">
                                            Unavailable
                                        </span>
                                    )}
                                    {ranges.map(([from, to], i) => (
                                        <span
                                            key={i}
                                            className="flex items-center gap-1"
                                        >
                                            <input
                                                type="time"
                                                aria-label={`${label} from`}
                                                className="h-8 rounded-md border bg-background px-2"
                                                value={from}
                                                onChange={(e) =>
                                                    setDay(
                                                        key,
                                                        ranges.map((r, j) =>
                                                            j === i
                                                                ? [
                                                                      e.target
                                                                          .value,
                                                                      r[1],
                                                                  ]
                                                                : r,
                                                        ),
                                                    )
                                                }
                                            />
                                            –
                                            <input
                                                type="time"
                                                aria-label={`${label} to`}
                                                className="h-8 rounded-md border bg-background px-2"
                                                value={to}
                                                onChange={(e) =>
                                                    setDay(
                                                        key,
                                                        ranges.map((r, j) =>
                                                            j === i
                                                                ? [
                                                                      r[0],
                                                                      e.target
                                                                          .value,
                                                                  ]
                                                                : r,
                                                        ),
                                                    )
                                                }
                                            />
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                aria-label={`Remove ${label} hours`}
                                                onClick={() =>
                                                    setDay(
                                                        key,
                                                        ranges.filter(
                                                            (_, j) => j !== i,
                                                        ),
                                                    )
                                                }
                                            >
                                                ×
                                            </Button>
                                        </span>
                                    ))}
                                    {ranges.length < 3 && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setDay(key, [
                                                    ...ranges,
                                                    ['09:00', '17:00'],
                                                ])
                                            }
                                        >
                                            Add hours
                                        </Button>
                                    )}
                                </div>
                            );
                        })}
                    </fieldset>

                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="grid gap-1">
                            Call length
                            <select
                                className="h-9 rounded-md border bg-background px-2"
                                value={form.data.slot_minutes}
                                onChange={(e) =>
                                    form.setData(
                                        'slot_minutes',
                                        parseInt(e.target.value, 10),
                                    )
                                }
                            >
                                {[15, 30, 45, 60].map((m) => (
                                    <option key={m} value={m}>
                                        {m} minutes
                                    </option>
                                ))}
                            </select>
                            <span className="text-muted-foreground">
                                Showings and listing appointments take an hour.
                            </span>
                        </label>
                        <label className="grid gap-1">
                            Minimum notice
                            <select
                                className="h-9 rounded-md border bg-background px-2"
                                value={form.data.notice_hours}
                                onChange={(e) =>
                                    form.setData(
                                        'notice_hours',
                                        parseInt(e.target.value, 10),
                                    )
                                }
                            >
                                {[0, 1, 2, 4, 12, 24, 48].map((h) => (
                                    <option key={h} value={h}>
                                        {h === 0
                                            ? 'None'
                                            : `${h} hour${h === 1 ? '' : 's'}`}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>

                    {Object.values(form.errors).map((e) => (
                        <p key={e} className="text-destructive">
                            {e}
                        </p>
                    ))}
                    <div>
                        <Button disabled={form.processing}>Save</Button>
                    </div>
                </form>

                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-medium">
                        Next open times for a call (as saved)
                    </h2>
                    {next.length === 0 ? (
                        <p className="text-muted-foreground">
                            None. Turn on website booking and add hours.
                        </p>
                    ) : (
                        <ul className="flex flex-wrap gap-2">
                            {next.map((n) => (
                                <li key={n}>
                                    <Badge variant="outline">{n}</Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className="text-muted-foreground">
                        Your open calls, showings and appointments in the CRM
                        are never offered. Booked times appear in your tasks
                        with a reminder 30 minutes before.
                    </p>
                </section>
            </div>
        </>
    );
}

Availability.layout = {
    breadcrumbs: [{ title: 'My availability', href: '/settings/availability' }],
};
