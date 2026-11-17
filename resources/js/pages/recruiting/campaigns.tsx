import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Step = {
    day: number;
    channel: 'email' | 'text';
    subject: string | null;
    body: string;
};
type Campaign = {
    ulid: string;
    name: string;
    steps: Step[];
    status: string;
    active: number;
    finished: number;
};

const blank: Step = { day: 0, channel: 'email', subject: '', body: '' };

function Editor({
    campaign,
    onDone,
}: {
    campaign: Campaign | null;
    onDone: () => void;
}) {
    const form = useForm<{ name: string; steps: Step[] }>({
        name: campaign?.name ?? '',
        steps: campaign?.steps ?? [{ ...blank }],
    });
    const set = (i: number, patch: Partial<Step>) =>
        form.setData(
            'steps',
            form.data.steps.map((s, j) => (j === i ? { ...s, ...patch } : s)),
        );

    return (
        <form
            className="grid gap-4 rounded-lg border p-4"
            onSubmit={(e) => {
                e.preventDefault();
                const opts = { preserveScroll: true, onSuccess: onDone };
                if (campaign) {
                    form.put(`/recruiting/campaigns/${campaign.ulid}`, opts);
                } else {
                    form.post('/recruiting/campaigns', opts);
                }
            }}
        >
            <label className="grid gap-1 text-sm">
                Campaign name
                <Input
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
            </label>
            <p className="text-sm text-muted-foreground">
                You can use {'{first_name}'}, {'{brokerage}'}, {'{recruiter}'}{' '}
                and {'{careers}'} (the careers page link). Every email gets your
                address and an unsubscribe link; every text ends “Reply STOP to
                opt out.”
            </p>
            <ol className="grid gap-3">
                {form.data.steps.map((s, i) => (
                    <li key={i} className="grid gap-2 rounded border p-3">
                        <div className="flex flex-wrap items-end gap-3">
                            <strong>Step {i + 1}</strong>
                            <label className="grid gap-1 text-sm">
                                Day
                                <Input
                                    className="w-20"
                                    type="number"
                                    min={0}
                                    max={120}
                                    value={s.day}
                                    onChange={(e) =>
                                        set(i, { day: Number(e.target.value) })
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Send by
                                <select
                                    className="h-10 rounded-md border bg-background px-3"
                                    value={s.channel}
                                    onChange={(e) =>
                                        set(i, {
                                            channel: e.target
                                                .value as Step['channel'],
                                        })
                                    }
                                >
                                    <option value="email">Email</option>
                                    <option value="text">
                                        Text (only if they agreed)
                                    </option>
                                </select>
                            </label>
                            {form.data.steps.length > 1 && (
                                <button
                                    type="button"
                                    className="text-sm underline"
                                    onClick={() =>
                                        form.setData(
                                            'steps',
                                            form.data.steps.filter(
                                                (_, j) => j !== i,
                                            ),
                                        )
                                    }
                                >
                                    Remove
                                </button>
                            )}
                        </div>
                        {s.channel === 'email' && (
                            <label className="grid gap-1 text-sm">
                                Subject
                                <Input
                                    value={s.subject ?? ''}
                                    onChange={(e) =>
                                        set(i, { subject: e.target.value })
                                    }
                                />
                            </label>
                        )}
                        <label className="grid gap-1 text-sm">
                            Message
                            <Textarea
                                rows={s.channel === 'email' ? 6 : 3}
                                value={s.body}
                                onChange={(e) =>
                                    set(i, { body: e.target.value })
                                }
                            />
                        </label>
                    </li>
                ))}
            </ol>
            <div className="flex flex-wrap gap-2">
                {form.data.steps.length < 8 && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            form.setData('steps', [
                                ...form.data.steps,
                                {
                                    ...blank,
                                    day: (form.data.steps.at(-1)?.day ?? 0) + 3,
                                },
                            ])
                        }
                    >
                        Add a step
                    </Button>
                )}
                <Button disabled={form.processing}>Save campaign</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
            {Object.values(form.errors).map((m) => (
                <p key={m} role="alert" className="text-sm text-red-700">
                    {m}
                </p>
            ))}
        </form>
    );
}

/** Recruiting campaigns (RC2): short email and text sequences a recruiter starts for one recruit at a time. */
export default function Campaigns({ campaigns }: { campaigns: Campaign[] }) {
    const [editing, setEditing] = useState<Campaign | 'new' | null>(null);

    return (
        <>
            <Head title="Recruiting campaigns" />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title="Recruiting campaigns"
                    description="A few friendly messages on set days. You start them from a recruit's page; they stop when someone joins or says not now."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/recruiting">
                                    Back to recruiting
                                </Link>
                            </Button>
                            <Button onClick={() => setEditing('new')}>
                                New campaign
                            </Button>
                        </div>
                    }
                />
                {editing !== null && (
                    <Editor
                        key={editing === 'new' ? 'new' : editing.ulid}
                        campaign={editing === 'new' ? null : editing}
                        onDone={() => setEditing(null)}
                    />
                )}
                <ul className="grid gap-3">
                    {campaigns.map((c) => (
                        <li
                            key={c.ulid}
                            className="grid gap-2 rounded-lg border p-4"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <strong className="text-lg">{c.name}</strong>
                                <Badge
                                    variant={
                                        c.status === 'active'
                                            ? 'outline'
                                            : 'secondary'
                                    }
                                >
                                    {c.status === 'active' ? 'On' : 'Paused'}
                                </Badge>
                                <span className="text-sm text-muted-foreground">
                                    {c.active} running · {c.finished} finished
                                </span>
                            </div>
                            <ol className="grid gap-1 text-sm text-muted-foreground">
                                {c.steps.map((s, i) => (
                                    <li key={i}>
                                        Day {s.day}:{' '}
                                        {s.channel === 'email'
                                            ? `email “${s.subject}”`
                                            : 'text'}
                                    </li>
                                ))}
                            </ol>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setEditing(c)}
                                >
                                    Edit
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            `/recruiting/campaigns/${c.ulid}/status`,
                                            { active: c.status !== 'active' },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {c.status === 'active'
                                        ? 'Pause'
                                        : 'Turn on'}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}
