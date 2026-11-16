import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import type { Notice } from '@/components/notification-bell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Kind = {
    type: string;
    label: string;
    group: string;
    urgent: boolean;
    in_app: boolean;
    email: boolean;
};
type Props = {
    notices: Notice[];
    kinds: Kind[];
    settings: {
        quiet_start: number | null;
        quiet_end: number | null;
        digest: boolean;
    };
    noisy: { type: string; label: string; sent: number; opened: number }[];
    timezone: string;
};

const hour = (h: number) =>
    `${h % 12 === 0 ? 12 : h % 12}${h < 12 ? 'am' : 'pm'}`;

function HourSelect({
    label,
    value,
    onChange,
}: {
    label: string;
    value: number;
    onChange: (h: number) => void;
}) {
    return (
        <select
            aria-label={label}
            className="rounded border bg-background px-2 py-1"
            value={value}
            onChange={(e) => onChange(Number(e.target.value))}
        >
            {Array.from({ length: 24 }, (_, h) => (
                <option key={h} value={h}>
                    {hour(h)}
                </option>
            ))}
        </select>
    );
}

/** The notification centre (specs/core/10, N1): every alert, and the person's choices. */
export default function Notifications({
    notices,
    kinds,
    settings,
    noisy,
    timezone,
}: Props) {
    const [tab, setTab] = useState<'inbox' | 'choices'>('inbox');
    const [quietOn, setQuietOn] = useState(settings.quiet_start !== null);
    const [start, setStart] = useState(settings.quiet_start ?? 21);
    const [end, setEnd] = useState(settings.quiet_end ?? 7);
    const [digest, setDigest] = useState(settings.digest);
    const opts = { preserveScroll: true };
    const read = (ulid?: string) =>
        router.post(`/notifications/read${ulid ? `/${ulid}` : ''}`, {}, opts);
    const pref = (type: string, inApp: boolean, email: boolean) =>
        router.post(
            '/notifications/preferences',
            { type, in_app: inApp, email },
            opts,
        );

    return (
        <>
            <Head title="Notifications" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Notifications"
                    description="Everything RightAlly told you, in one place, and how you want to hear about it."
                    actions={
                        <Button variant="outline" onClick={() => read()}>
                            Mark all read
                        </Button>
                    }
                />
                <div className="flex gap-2" role="tablist">
                    <Button
                        role="tab"
                        aria-selected={tab === 'inbox'}
                        variant={tab === 'inbox' ? 'default' : 'outline'}
                        onClick={() => setTab('inbox')}
                    >
                        Inbox
                    </Button>
                    <Button
                        role="tab"
                        aria-selected={tab === 'choices'}
                        variant={tab === 'choices' ? 'default' : 'outline'}
                        onClick={() => setTab('choices')}
                    >
                        What you get
                    </Button>
                </div>
                {noisy.length > 0 && (
                    <section className="grid gap-2 rounded-lg border border-amber-500 p-3 text-sm">
                        <p className="font-medium">Fewer, better alerts</p>
                        {noisy.map((n) => (
                            <p
                                key={n.type}
                                className="flex flex-wrap items-center gap-2"
                            >
                                You opened {n.opened} of the last {n.sent} "
                                {n.label}" alerts.
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => pref(n.type, true, false)}
                                >
                                    Stop emailing these
                                </Button>
                            </p>
                        ))}
                    </section>
                )}
                {tab === 'inbox' ? (
                    <ul className="grid gap-2">
                        {notices.length === 0 && (
                            <li className="text-sm text-muted-foreground">
                                Nothing yet. Alerts about leads, deals, payouts
                                and your brokerage will show here.
                            </li>
                        )}
                        {notices.map((n) => (
                            <li
                                key={n.ulid}
                                className="grid gap-1 rounded-lg border p-3 text-sm md:grid-cols-[1fr_auto]"
                            >
                                <div className="grid gap-0.5">
                                    <p className="flex flex-wrap items-center gap-2">
                                        {!n.read && <Badge>New</Badge>}
                                        {n.urgent && (
                                            <Badge variant="destructive">
                                                Urgent
                                            </Badge>
                                        )}
                                        <span
                                            className={
                                                n.read ? '' : 'font-medium'
                                            }
                                        >
                                            {n.title}
                                        </span>
                                    </p>
                                    {n.body && (
                                        <p className="text-muted-foreground">
                                            {n.body}
                                        </p>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        {n.label} ·{' '}
                                        {new Date(n.at).toLocaleString()}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-start gap-2">
                                    {n.url && (
                                        <Button size="sm" asChild>
                                            <Link
                                                href={n.url}
                                                onClick={() => read(n.ulid)}
                                            >
                                                Take me there
                                            </Link>
                                        </Button>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/notifications/${n.ulid}/snooze`,
                                                { hours: 24 },
                                                opts,
                                            )
                                        }
                                    >
                                        Snooze a day
                                    </Button>
                                    {!n.read && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => read(n.ulid)}
                                        >
                                            Mark read
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <div className="grid gap-4">
                        <section className="grid gap-3 rounded-lg border p-4 text-sm">
                            <p className="font-medium">
                                Quiet hours and the daily digest
                            </p>
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={quietOn}
                                    onChange={(e) =>
                                        setQuietOn(e.target.checked)
                                    }
                                />
                                Hold emails during quiet hours (urgent ones
                                still come through)
                            </label>
                            {quietOn && (
                                <span className="flex flex-wrap items-center gap-2">
                                    From
                                    <HourSelect
                                        label="Quiet hours start"
                                        value={start}
                                        onChange={setStart}
                                    />
                                    to
                                    <HourSelect
                                        label="Quiet hours end"
                                        value={end}
                                        onChange={setEnd}
                                    />
                                    ({timezone})
                                </span>
                            )}
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={digest}
                                    onChange={(e) =>
                                        setDigest(e.target.checked)
                                    }
                                />
                                Send one email a day at 7am instead of one per
                                alert (urgent ones still come at once)
                            </label>
                            <Button
                                className="justify-self-start"
                                onClick={() =>
                                    router.post(
                                        '/notifications/settings',
                                        {
                                            quiet_start: quietOn ? start : null,
                                            quiet_end: quietOn ? end : null,
                                            digest,
                                        },
                                        opts,
                                    )
                                }
                            >
                                Save
                            </Button>
                        </section>
                        <section className="grid gap-2 overflow-x-auto rounded-lg border p-4 text-sm">
                            <p className="font-medium">Each kind of alert</p>
                            <table className="w-full text-left">
                                <thead>
                                    <tr className="text-muted-foreground">
                                        <th className="py-1 font-normal">
                                            Alert
                                        </th>
                                        <th className="py-1 font-normal">
                                            In RightAlly
                                        </th>
                                        <th className="py-1 font-normal">
                                            Email
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {kinds.map((k) => (
                                        <tr key={k.type} className="border-t">
                                            <td className="py-2">
                                                {k.label}
                                                {k.urgent && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2"
                                                    >
                                                        Urgent
                                                    </Badge>
                                                )}
                                            </td>
                                            <td>
                                                <input
                                                    type="checkbox"
                                                    className="size-4"
                                                    aria-label={`${k.label} in RightAlly`}
                                                    checked={k.in_app}
                                                    onChange={(e) =>
                                                        pref(
                                                            k.type,
                                                            e.target.checked,
                                                            k.email,
                                                        )
                                                    }
                                                />
                                            </td>
                                            <td>
                                                <input
                                                    type="checkbox"
                                                    className="size-4"
                                                    aria-label={`${k.label} by email`}
                                                    checked={k.email}
                                                    onChange={(e) =>
                                                        pref(
                                                            k.type,
                                                            k.in_app,
                                                            e.target.checked,
                                                        )
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <p className="text-muted-foreground">
                                Sign-in codes, password resets, receipts and
                                security messages always come by email.
                            </p>
                        </section>
                    </div>
                )}
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [{ title: 'Notifications', href: '/notifications' }],
};
