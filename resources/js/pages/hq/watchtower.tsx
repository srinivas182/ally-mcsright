import { Head, Link, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { StatCard } from '@/components/ui/stat-card';
import { Switch } from '@/components/ui/switch';

type Row = {
    id: string;
    reference: string;
    kind: string;
    severity: string;
    status: string;
    title: string;
    location: string | null;
    occurrences: number;
    last_hour: number;
    brokerages: number;
    ongoing: boolean;
    regressed: boolean;
    explained: boolean;
    last_seen_at: string;
};
type Check = {
    name: string;
    label: string;
    ok: boolean;
    message: string | null;
    failing_since: string | null;
};
type Settings = {
    recipients: string[];
    alerts: boolean;
    digest: boolean;
    ai: boolean;
    ai_daily_cap: number;
    browser_errors: boolean;
    slow_pages: boolean;
};
type Props = {
    filters: { view: string; severity: string; kind: string };
    views: Record<string, string>;
    severities: Record<string, string>;
    kinds: Record<string, string>;
    release: string;
    summary: { critical: number; high: number; normal: number; today: number };
    checks: Check[];
    tasks: {
        task: string;
        last_started_at: string | null;
        last_status: string | null;
    }[];
    scheduler: string | null;
    uptime: {
        monitor: string;
        uptime_pct: number;
        down_minutes: number;
        down_now: boolean;
    }[];
    slow: {
        route: string;
        samples: number;
        over_budget_pct: number;
        over_2s: number;
        max_ms: number;
    }[];
    settings: Settings;
    aiToday: number;
    uptimeUrl: string | null;
    heartbeatUrl: string;
    tracking: { provider: string; mode: string } | null;
    rows: {
        data: Row[];
        total: number;
        next: string | null;
        prev: string | null;
    };
};

const TONE: Record<string, 'destructive' | 'default' | 'secondary'> = {
    critical: 'destructive',
    high: 'default',
    normal: 'secondary',
};
const PROVIDERS: Record<string, string> = {
    sentry: 'Sentry',
    bugsnag: 'Bugsnag',
    honeybadger: 'Honeybadger',
};
const when = (iso: string | null) =>
    iso === null ? 'never' : new Date(iso).toLocaleString();

/**
 * HQ › Watchtower (OB1): every problem on RightAlly, the state of each system, and the switches for what happens
 * when something breaks — emails, the daily report, the AI's root cause, browser errors, slow pages, the uptime
 * checker and Sentry.
 */
export default function Watchtower(props: Props) {
    const { filters, summary, checks, rows } = props;
    const filter = (key: string, value: string) =>
        router.get(
            '/hq/watchtower',
            { ...filters, [key]: value },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <>
            <Head title="Watchtower" />
            <div className="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Watchtower"
                    description={`Watches all of RightAlly: errors, failed jobs, scheduled tasks, databases, slow pages and downtime. The AI finds the likely root cause and the people below get an email with a fix request to paste. Running release ${props.release}.`}
                />

                <div className="grid gap-3 sm:grid-cols-4">
                    <StatCard label="Critical, open" value={summary.critical} />
                    <StatCard label="High, open" value={summary.high} />
                    <StatCard label="Normal, open" value={summary.normal} />
                    <StatCard label="New today" value={summary.today} />
                </div>

                <section className="grid gap-2">
                    <h2 className="text-lg font-medium">Systems</h2>
                    {checks.length === 0 && (
                        <p className="text-muted-foreground">
                            No checks yet. They run every minute once the
                            scheduler is running.
                        </p>
                    )}
                    <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {checks.map((c) => (
                            <li
                                key={c.name}
                                className="flex items-start justify-between gap-2 rounded-md border p-3"
                            >
                                <span>
                                    {c.label}
                                    {!c.ok && (
                                        <span className="block text-sm text-destructive">
                                            {c.message}
                                        </span>
                                    )}
                                </span>
                                <Badge
                                    variant={c.ok ? 'secondary' : 'destructive'}
                                >
                                    {c.ok ? 'Working' : 'Failing'}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                    <p className="text-sm text-muted-foreground">
                        Scheduler last checked in: {when(props.scheduler)}.{' '}
                        {props.uptime.map((u) => (
                            <span key={u.monitor}>
                                {u.monitor}: {u.uptime_pct}% up over 30 days
                                {u.down_now ? ' (down now)' : ''}.{' '}
                            </span>
                        ))}
                    </p>
                </section>

                <section className="grid gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="mr-auto text-lg font-medium">
                            Problems ({rows.total})
                        </h2>
                        <select
                            aria-label="Show"
                            className="h-9 rounded-md border bg-background px-2"
                            value={filters.view}
                            onChange={(e) => filter('view', e.target.value)}
                        >
                            {Object.entries(props.views).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Severity"
                            className="h-9 rounded-md border bg-background px-2"
                            value={filters.severity}
                            onChange={(e) => filter('severity', e.target.value)}
                        >
                            <option value="">Any severity</option>
                            {Object.entries(props.severities).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Kind"
                            className="h-9 rounded-md border bg-background px-2"
                            value={filters.kind}
                            onChange={(e) => filter('kind', e.target.value)}
                        >
                            <option value="">Any kind</option>
                            {Object.entries(props.kinds).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    </div>
                    {rows.data.length === 0 && (
                        <p className="text-muted-foreground">
                            Nothing here. Good news.
                        </p>
                    )}
                    <ul className="grid gap-2">
                        {rows.data.map((r) => (
                            <li key={r.id}>
                                <Link
                                    href={`/hq/watchtower/${r.id}`}
                                    className="grid gap-1 rounded-md border p-3 hover:bg-muted/50"
                                >
                                    <span className="flex flex-wrap items-center gap-2">
                                        <Badge variant={TONE[r.severity]}>
                                            {props.severities[r.severity]}
                                        </Badge>
                                        <span className="font-medium">
                                            {r.title}
                                        </span>
                                        {r.regressed && (
                                            <Badge variant="destructive">
                                                Came back
                                            </Badge>
                                        )}
                                        {r.explained && (
                                            <Badge variant="secondary">
                                                Root cause found
                                            </Badge>
                                        )}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {r.reference} · {props.kinds[r.kind]} ·{' '}
                                        {r.occurrences}× ({r.last_hour} in the
                                        last hour)
                                        {r.brokerages > 0 &&
                                            ` · ${r.brokerages} brokerage${r.brokerages === 1 ? '' : 's'}`}{' '}
                                        · last {when(r.last_seen_at)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <div className="flex gap-2">
                        {rows.prev && (
                            <Button variant="outline" asChild>
                                <Link href={rows.prev}>Newer</Link>
                            </Button>
                        )}
                        {rows.next && (
                            <Button variant="outline" asChild>
                                <Link href={rows.next}>Older</Link>
                            </Button>
                        )}
                    </div>
                </section>

                {props.slow.length > 0 && (
                    <section className="grid gap-1">
                        <h2 className="text-lg font-medium">
                            Slowest pages, last 24 hours
                        </h2>
                        {props.slow.map((s) => (
                            <p key={s.route} className="text-sm">
                                {s.route}: {s.over_budget_pct}% over 300 ms,{' '}
                                {s.over_2s} over 2 s, slowest {s.max_ms} ms
                            </p>
                        ))}
                    </section>
                )}

                <SettingsForm {...props} />

                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="text-lg font-medium">Outside checks</h2>
                    <p>
                        <strong>Error tracking:</strong>{' '}
                        {props.tracking
                            ? `${PROVIDERS[props.tracking.provider] ?? props.tracking.provider}, ${props.tracking.mode === 'live' ? 'Live' : 'Test'}.`
                            : 'Off. Problems stay here only.'}{' '}
                        <Link
                            href="/hq/services"
                            className="text-primary underline"
                        >
                            Switch Sentry on or off in Outside services
                        </Link>
                    </p>
                    <p>
                        <strong>Uptime checker</strong> (Better Stack
                        recommended, or UptimeRobot): it emails you itself when
                        RightAlly is down. Have it check{' '}
                        <code className="break-all">{props.heartbeatUrl}</code>{' '}
                        (fails when the scheduler stops) and send its alerts to:
                    </p>
                    <p>
                        {props.uptimeUrl ? (
                            <code className="break-all">{props.uptimeUrl}</code>
                        ) : (
                            <span className="text-muted-foreground">
                                No address yet.
                            </span>
                        )}
                    </p>
                    <Button
                        variant="outline"
                        className="justify-self-start"
                        onClick={() =>
                            router.post(
                                '/hq/watchtower/uptime-address',
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {props.uptimeUrl
                            ? 'Make a new address (the old one stops)'
                            : 'Make the address'}
                    </Button>
                </section>
            </div>
        </>
    );
}

function SettingsForm({ settings, aiToday }: Props) {
    const form = useForm({
        ...settings,
        recipients: settings.recipients.join(', '),
    });
    const toggles: [keyof Settings, string, string][] = [
        [
            'alerts',
            'Email problems as they happen',
            'Critical and high problems, one email per problem, with the fix request attached.',
        ],
        [
            'digest',
            'Daily report',
            'Every morning at 8 am Central: new problems, what is open, what is failing.',
        ],
        [
            'ai',
            'AI finds the root cause',
            `The AI reads the error and our code and suggests the fix. Paid by HQ. Used ${aiToday} today.`,
        ],
        [
            'browser_errors',
            'Browser errors',
            'Crashes on screen in people’s browsers.',
        ],
        [
            'slow_pages',
            'Slow pages',
            'Pages where more than 5% of visits take over 300 ms.',
        ],
    ];

    return (
        <form
            className="grid gap-4 rounded-lg border p-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    recipients: d.recipients
                        .split(/[,\s]+/)
                        .map((x) => x.trim())
                        .filter((x) => x !== ''),
                }));
                form.post('/hq/watchtower/settings', { preserveScroll: true });
            }}
        >
            <h2 className="text-lg font-medium">Switches</h2>
            <label className="grid gap-1">
                Who gets the emails (up to 5, comma between each)
                <Input
                    value={form.data.recipients}
                    placeholder="dev@yourcompany.com, you@rightally.io"
                    onChange={(e) => form.setData('recipients', e.target.value)}
                />
                {Object.entries(form.errors)
                    .filter(([k]) => k.startsWith('recipients'))
                    .map(([k, v]) => (
                        <span key={k} className="text-sm text-destructive">
                            {v}
                        </span>
                    ))}
            </label>
            {toggles.map(([key, label, about]) => (
                <label key={key} className="flex items-start gap-3">
                    <Switch
                        checked={Boolean(form.data[key])}
                        onCheckedChange={(on) => form.setData(key, on)}
                        aria-label={label}
                    />
                    <span>
                        {label}
                        <span className="block text-sm text-muted-foreground">
                            {about}
                        </span>
                    </span>
                </label>
            ))}
            <label className="grid max-w-xs gap-1">
                Most problems the AI explains a day
                <Input
                    type="number"
                    min={0}
                    max={500}
                    value={form.data.ai_daily_cap}
                    onChange={(e) =>
                        form.setData('ai_daily_cap', Number(e.target.value))
                    }
                />
            </label>
            <Button
                type="submit"
                className="justify-self-start"
                disabled={form.processing}
            >
                Save switches
            </Button>
        </form>
    );
}
