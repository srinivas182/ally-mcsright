import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Item = {
    title: string;
    location: string;
    category: string;
    page: number | null;
    quote: string;
    ask: string;
    credit_cents: number;
    note: string;
};
type Request = {
    ulid: string;
    document: string;
    status: string;
    items: Item[];
    packet: string | null;
    shared: boolean;
    credit_cents: number;
    at: string;
};
type Props = {
    deal: { ulid: string; street: string; locked: boolean };
    documents: { ulid: string; filename: string }[];
    requests: Request[];
    deadline: { label: string; date: string } | null;
    categories: Record<string, string>;
    packet: string | null;
};

function Editor({
    deal,
    request,
    categories,
}: {
    deal: string;
    request: Request;
    categories: Record<string, string>;
}) {
    const base = `/deals/${deal}/repairs/${request.ulid}`;
    const form = useForm({
        items: request.items.map((i) => ({
            ...i,
            credit: i.credit_cents ? String(i.credit_cents / 100) : '',
        })),
    });
    const set = (n: number, patch: Partial<(typeof form.data.items)[number]>) =>
        form.setData(
            'items',
            form.data.items.map((x, i) => (i === n ? { ...x, ...patch } : x)),
        );
    const credit = form.data.items.reduce(
        (sum, i) =>
            sum +
            (i.ask === 'credit' ? Math.round(Number(i.credit || 0) * 100) : 0),
        0,
    );
    const asked = form.data.items.filter((i) => i.ask !== 'none').length;

    return (
        <section className="grid gap-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <p className="font-medium">From {request.document}</p>
                <Badge variant="outline">{request.at}</Badge>
                {request.status === 'failed' && (
                    <Badge variant="destructive">
                        Could not be read: add the items yourself
                    </Badge>
                )}
                <span className="text-muted-foreground">
                    {asked} asked for · credits {usd(credit)}
                </span>
            </div>
            <form
                className="grid gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => ({ items: d.items }));
                    form.post(base, { preserveScroll: true });
                }}
            >
                {Object.entries(categories).map(([key, label]) => {
                    const rows = form.data.items
                        .map((it, n) => ({ it, n }))
                        .filter(({ it }) => it.category === key);
                    return rows.length === 0 ? null : (
                        <fieldset key={key} className="grid gap-2">
                            <legend className="font-medium">
                                {label} ({rows.length})
                            </legend>
                            {rows.map(({ it, n }) => (
                                <div
                                    key={n}
                                    className="grid gap-1 rounded-md border p-2 md:grid-cols-[1fr_auto_auto]"
                                >
                                    <span>
                                        <strong>{it.title}</strong>
                                        {it.location && ` · ${it.location}`}
                                        {it.page && (
                                            <span className="block text-xs text-muted-foreground">
                                                Report page {it.page}: “
                                                {it.quote}”
                                            </span>
                                        )}
                                        <Input
                                            aria-label={`Note for ${it.title}`}
                                            placeholder="Note (optional), e.g. by a licensed electrician"
                                            className="mt-1"
                                            value={it.note}
                                            onChange={(e) =>
                                                set(n, { note: e.target.value })
                                            }
                                        />
                                    </span>
                                    <select
                                        aria-label={`Ask for ${it.title}`}
                                        className="h-9 self-start rounded border bg-background px-2"
                                        value={it.ask}
                                        onChange={(e) =>
                                            set(n, { ask: e.target.value })
                                        }
                                    >
                                        <option value="repair">
                                            Ask for a repair
                                        </option>
                                        <option value="credit">
                                            Ask for a credit
                                        </option>
                                        <option value="none">
                                            Don&apos;t ask
                                        </option>
                                    </select>
                                    {it.ask === 'credit' ? (
                                        <Input
                                            aria-label={`Credit for ${it.title} ($)`}
                                            placeholder="Credit ($)"
                                            type="number"
                                            className="w-32 self-start"
                                            value={it.credit}
                                            onChange={(e) =>
                                                set(n, {
                                                    credit: e.target.value,
                                                })
                                            }
                                        />
                                    ) : (
                                        <span />
                                    )}
                                </div>
                            ))}
                        </fieldset>
                    );
                })}
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="justify-self-start"
                    onClick={() =>
                        form.setData('items', [
                            ...form.data.items,
                            {
                                title: 'New item',
                                location: '',
                                category: 'minor',
                                page: null,
                                quote: '',
                                ask: 'repair',
                                credit_cents: 0,
                                note: '',
                                credit: '',
                            },
                        ])
                    }
                >
                    Add an item
                </Button>
                {(form.errors as Record<string, string>).items && (
                    <span className="text-destructive">
                        {(form.errors as Record<string, string>).items}
                    </span>
                )}
                <span className="flex flex-wrap gap-2">
                    <Button type="submit" size="sm" disabled={form.processing}>
                        Save
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                `${base}/draft`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Draft the repair amendment
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            router.post(
                                `${base}/share`,
                                { share: !request.shared },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {request.shared
                            ? 'Stop showing the buyers'
                            : 'Show the buyers in their portal'}
                    </Button>
                </span>
            </form>
            {request.packet && (
                <Link
                    className="underline"
                    href={`/forms/packets/${request.packet}`}
                >
                    Open the amendment draft
                </Link>
            )}
        </section>
    );
}

/** Repair requests from the inspection report (specs/ai/02, DQ4). */
export default function Repairs({
    deal,
    documents,
    requests,
    deadline,
    categories,
    packet,
}: Props) {
    const file = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title={`Repairs: ${deal.street}`} />
            <div className="flex flex-col gap-4 p-4 text-sm md:p-6">
                <PageHeader
                    title={`Repair request: ${deal.street}`}
                    description="Deal-IQ lists the inspector's findings with the page each came from. You choose what to ask for; amounts are yours, never estimated by AI."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={`/deals/${deal.ulid}`}>
                                Back to the deal
                            </Link>
                        </Button>
                    }
                />
                {deadline && (
                    <p className="rounded-md border border-amber-500 p-3">
                        Send it before <strong>{deadline.label}</strong> on{' '}
                        {new Date(`${deadline.date}T12:00:00`).toDateString()}.
                    </p>
                )}
                {packet && (
                    <p role="status" className="rounded-md border p-3">
                        <Link
                            className="underline"
                            href={`/forms/packets/${packet}`}
                        >
                            The repair amendment is drafted: check it and send
                            it for signature.
                        </Link>
                    </p>
                )}
                {!deal.locked && (
                    <section className="flex flex-wrap items-center gap-2 rounded-lg border p-4">
                        <span>Read an inspection report:</span>
                        {documents.slice(0, 8).map((d) => (
                            <Button
                                key={d.ulid}
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/deals/${deal.ulid}/repairs`,
                                        { document: d.ulid },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {d.filename}
                            </Button>
                        ))}
                        <input
                            ref={file}
                            type="file"
                            className="hidden"
                            accept="application/pdf,image/*"
                            aria-label="Inspection report"
                            onChange={(e) => {
                                const f = e.target.files?.[0];
                                if (f) {
                                    router.post(
                                        `/deals/${deal.ulid}/repairs`,
                                        { file: f },
                                        {
                                            forceFormData: true,
                                            preserveScroll: true,
                                        },
                                    );
                                }
                            }}
                        />
                        <Button size="sm" onClick={() => file.current?.click()}>
                            Upload the report
                        </Button>
                    </section>
                )}
                {requests.length === 0 && (
                    <p className="text-muted-foreground">
                        No repair requests yet.
                    </p>
                )}
                {requests.map((r) => (
                    <Editor
                        key={r.ulid + r.status + r.items.length}
                        deal={deal.ulid}
                        request={r}
                        categories={categories}
                    />
                ))}
            </div>
        </>
    );
}

Repairs.layout = { breadcrumbs: [{ title: 'Deals', href: '/deals' }] };
