import { Head, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Registration = {
    tenant_id: number;
    brokerage: string;
    status: string;
    status_label: string;
    note: string | null;
    submitted_at: string | null;
    brand: Record<string, string>;
    campaign: Record<string, string>;
};

/** HQ → Texting registrations: submit each brokerage's A2P 10DLC answers to the carrier registry and record the outcome. */
export default function HqTexting({
    registrations,
}: {
    registrations: Registration[];
}) {
    return (
        <>
            <Head title="Texting registrations" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Texting registrations"
                    description="Brokerages' A2P 10DLC answers. Submit them in the Twilio console (Trust Hub), then record what the carriers decided."
                />
                {registrations.length === 0 && (
                    <p className="text-muted-foreground">None waiting.</p>
                )}
                {registrations.map((r) => (
                    <Row key={r.tenant_id} r={r} />
                ))}
            </div>
        </>
    );
}

function Row({ r }: { r: Registration }) {
    const form = useForm({ status: 'approved', note: r.note ?? '' });

    return (
        <section className="grid gap-2 rounded-lg border p-4 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-semibold">{r.brokerage}</h2>
                <Badge variant="outline">{r.status_label}</Badge>
                {r.submitted_at && (
                    <span className="text-muted-foreground">
                        sent {r.submitted_at}
                    </span>
                )}
            </div>
            <dl className="grid gap-1 sm:grid-cols-2">
                {Object.entries({ ...r.brand, ...r.campaign }).map(([k, v]) => (
                    <div key={k}>
                        <dt className="text-muted-foreground">{k}</dt>
                        <dd className="break-words">{v}</dd>
                    </div>
                ))}
            </dl>
            <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/hq/texting/${r.tenant_id}`, {
                        preserveScroll: true,
                    });
                }}
            >
                <select
                    aria-label="Outcome"
                    className="rounded-md border bg-background p-2"
                    value={form.data.status}
                    onChange={(e) => form.setData('status', e.target.value)}
                >
                    <option value="approved">Approved</option>
                    <option value="rejected">Needs changes</option>
                </select>
                <Input
                    aria-label="Note for the brokerage"
                    className="max-w-md"
                    placeholder="Note for the brokerage (what to change)"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
                <Button disabled={form.processing}>Record</Button>
            </form>
        </section>
    );
}
