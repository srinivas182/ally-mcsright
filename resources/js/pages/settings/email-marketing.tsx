import { Head, Link, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

type Conn = {
    ulid: string;
    app: string;
    name: string;
    brokerage: boolean;
    audiences: { id: string; name: string; members: number | null }[];
    error: string | null;
    audience: string | null;
    who: 'consented' | 'not_opted_out';
    in_audience: number;
    last_push_at: string | null;
    unsubs_month: number;
};

function ConnectionForm({ c }: { c: Conn }) {
    const form = useForm({
        audience: c.audience ?? c.audiences[0]?.id ?? '',
        who: c.who,
        permission: false,
    });

    return (
        <form
            className="grid gap-3 rounded-lg border p-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/settings/email-marketing/${c.ulid}`, {
                    preserveScroll: true,
                });
            }}
        >
            <h2 className="font-medium">
                {c.name} {c.brokerage ? '(brokerage)' : '(yours)'}
            </h2>
            {c.error ? (
                <p className="text-destructive">{c.error}</p>
            ) : (
                <>
                    <label className="grid gap-1">
                        {c.app === 'mailchimp' ? 'Audience' : 'List'}
                        <select
                            className="h-12 rounded-md border bg-background px-3"
                            value={form.data.audience}
                            onChange={(e) =>
                                form.setData('audience', e.target.value)
                            }
                        >
                            {c.audiences.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                    {a.members !== null
                                        ? ` (${a.members.toLocaleString()})`
                                        : ''}
                                </option>
                            ))}
                        </select>
                    </label>
                    <fieldset className="grid gap-1">
                        <legend>Who goes</legend>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name={`who-${c.ulid}`}
                                checked={form.data.who === 'consented'}
                                onChange={() =>
                                    form.setData('who', 'consented')
                                }
                            />
                            Only contacts who agreed to email (recommended)
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name={`who-${c.ulid}`}
                                checked={form.data.who === 'not_opted_out'}
                                onChange={() =>
                                    form.setData('who', 'not_opted_out')
                                }
                            />
                            Everyone with an email address who hasn't opted out
                        </label>
                        {form.data.who === 'not_opted_out' && (
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="mt-1 size-4"
                                    checked={form.data.permission}
                                    onChange={(e) =>
                                        form.setData(
                                            'permission',
                                            e.target.checked,
                                        )
                                    }
                                />
                                I confirm we have permission to email these
                                people. {c.name} requires it.
                            </label>
                        )}
                        {form.errors.permission && (
                            <span className="text-destructive">
                                {form.errors.permission}
                            </span>
                        )}
                    </fieldset>
                    <p className="text-sm text-muted-foreground">
                        {c.in_audience.toLocaleString()} sent so far
                        {c.last_push_at
                            ? `, last on ${new Date(c.last_push_at).toLocaleString()}`
                            : ''}
                        . {c.unsubs_month} unsubscribed this month and get no
                        more email here. Opted-out contacts are never sent.
                    </p>
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </div>
                </>
            )}
        </form>
    );
}

/** Settings → Email marketing (specs/connect/15, C7a): the audience and who goes, per connected app. */
export default function EmailMarketing({
    connections,
}: {
    connections: Conn[];
}) {
    return (
        <>
            <Head title="Email marketing" />
            <div className="grid max-w-2xl gap-6 p-4 text-base">
                <PageHeader
                    title="Email marketing"
                    description="Contacts go to your Mailchimp or Constant Contact audience every hour; anyone who unsubscribes there gets no more email here."
                />
                {connections.length === 0 ? (
                    <EmptyState
                        title="No email app connected"
                        description="Connect Mailchimp or Constant Contact under Apps first."
                        action={
                            <Button asChild>
                                <Link href="/apps">Go to Apps</Link>
                            </Button>
                        }
                    />
                ) : (
                    connections.map((c) => (
                        <ConnectionForm key={c.ulid} c={c} />
                    ))
                )}
            </div>
        </>
    );
}
