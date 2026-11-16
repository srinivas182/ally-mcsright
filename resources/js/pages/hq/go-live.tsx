import { Head, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Row = {
    tenant: string;
    name: string;
    day: number;
    done: number;
    total: number;
    late: string[];
    adoption: { agents: number; signed_in: number; active_week: number };
    coach: { name: string | null; email: string | null; phone: string | null };
};
type Signup = {
    brokerage: string;
    email: string;
    slug: string;
    status: string;
    error: string | null;
    at: string;
};

function Coach({ row }: { row: Row }) {
    const form = useForm({
        name: row.coach.name ?? '',
        email: row.coach.email ?? '',
        phone: row.coach.phone ?? '',
    });

    return (
        <form
            className="flex flex-wrap gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/hq/go-live/${row.tenant}/coach`, {
                    preserveScroll: true,
                });
            }}
        >
            <Input
                aria-label="Coach name"
                placeholder="Coach name"
                className="w-36"
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
            />
            <Input
                aria-label="Coach email"
                placeholder="Email"
                className="w-44"
                value={form.data.email}
                onChange={(e) => form.setData('email', e.target.value)}
            />
            <Input
                aria-label="Coach phone"
                placeholder="Phone"
                className="w-32"
                value={form.data.phone}
                onChange={(e) => form.setData('phone', e.target.value)}
            />
            <Button size="sm" variant="outline" disabled={form.processing}>
                Save
            </Button>
        </form>
    );
}

/** HQ → Go-live: every new brokerage's 30-day plan, which steps are late, agent adoption, and its coach. */
export default function HqGoLive({
    plans,
    signups,
}: {
    plans: Row[];
    signups: Signup[];
}) {
    return (
        <>
            <Head title="Go-live" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Go-live"
                    description="New brokerages in their first 30 days. Steps tick themselves from what each brokerage has done; late steps are the ones to call about."
                />
                {signups.length > 0 && (
                    <section className="grid gap-2 rounded-lg border p-3">
                        <h2 className="font-semibold">
                            Signups waiting or needing a hand
                        </h2>
                        {signups.map((s) => (
                            <p key={s.slug + s.at} className="text-sm">
                                <Badge
                                    variant={
                                        s.status === 'failed'
                                            ? 'destructive'
                                            : 'outline'
                                    }
                                >
                                    {s.status === 'failed'
                                        ? 'Paid, set up by hand'
                                        : 'Waiting for payment'}
                                </Badge>{' '}
                                {s.brokerage} ({s.slug}) · {s.email}
                                {s.error && ` · ${s.error}`}
                            </p>
                        ))}
                    </section>
                )}
                {plans.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No brokerages in go-live yet.
                    </p>
                ) : (
                    <ul className="grid gap-3">
                        {plans.map((p) => (
                            <li
                                key={p.tenant}
                                className="grid gap-2 rounded-lg border p-3"
                            >
                                <div className="flex flex-wrap items-baseline gap-2">
                                    <a
                                        className="font-semibold underline"
                                        href={`/hq/tenants/${p.tenant}`}
                                    >
                                        {p.name}
                                    </a>
                                    <span className="text-sm text-muted-foreground">
                                        Day {p.day} · {p.done}/{p.total} steps ·{' '}
                                        {p.adoption.signed_in}/
                                        {p.adoption.agents} agents signed in ·{' '}
                                        {p.adoption.active_week} active this
                                        week
                                    </span>
                                    {p.late.length > 0 && (
                                        <Badge variant="destructive">
                                            {p.late.length} late
                                        </Badge>
                                    )}
                                </div>
                                {p.late.length > 0 && (
                                    <p className="text-sm">
                                        Late: {p.late.join(' · ')}
                                    </p>
                                )}
                                <Coach row={p} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
