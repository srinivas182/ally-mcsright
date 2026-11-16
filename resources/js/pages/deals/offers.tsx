import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

import {
    OfferForm,
    type OfferOptions,
    type OfferTerms,
} from '@/components/deals/offer-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Net = {
    price_cents: number;
    listing_cents: number;
    title_cents: number;
    concessions_cents: number;
    buyer_agent_cents: number;
    payoff_cents: number;
    other_cents: number;
    net_cents: number;
};
type Offer = OfferTerms & {
    ulid: string;
    label: string;
    status: string;
    round: number;
    effective_cents: number;
    escalates: boolean;
    financing: string;
    proof: string;
    contingencies: string[];
    net: Net;
    certainty: { score: number; grade: string; reasons: string[] };
    source: string;
    has_file: boolean;
    packet: string | null;
    read: Record<string, { value: string; page: number; quote: string }>;
};
type Props = {
    deal: {
        ulid: string;
        street: string;
        list_price_cents: number;
        locked: boolean;
        status: string;
    };
    offers: Offer[];
    sheet: {
        listing_percent: number;
        title_percent: number;
        payoff_cents: number;
        other_cents: number;
    };
    canSeeCommission: boolean;
    hasLink: boolean;
    link: string | null;
    notices: {
        kind: string;
        message: string;
        deadline: string | null;
        to: number;
        recipients: { offer: string; email: string }[];
        at: string;
    }[];
    deadline: string | null;
    summary: {
        en: string;
        es: string | null;
        ai: boolean;
        shared: boolean;
        at: string;
    } | null;
    packet: string | null;
    options: OfferOptions;
};

const open = (s: string) =>
    ['received', 'shortlisted', 'countered'].includes(s);
const when = (iso: string) => new Date(iso).toLocaleString();

/** Reverse the table's labels back to option keys for editing. */
function keyOf(options: Record<string, string>, label: string): string {
    return Object.entries(options).find(([, l]) => l === label)?.[0] ?? '';
}

function Counter({ base, offer }: { base: string; offer: Offer }) {
    const form = useForm({
        price: '',
        closing_date: '',
        concessions: '',
        note: '',
    });

    return (
        <form
            className="grid gap-2 md:grid-cols-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`${base}/${offer.ulid}/counter`, {
                    preserveScroll: true,
                });
            }}
        >
            <Input
                aria-label="Counter price ($)"
                placeholder="Price ($)"
                type="number"
                value={form.data.price}
                onChange={(e) => form.setData('price', e.target.value)}
            />
            <Input
                aria-label="Counter closing date"
                type="date"
                value={form.data.closing_date}
                onChange={(e) => form.setData('closing_date', e.target.value)}
            />
            <Input
                aria-label="Seller concessions ($)"
                placeholder="Concessions ($)"
                type="number"
                value={form.data.concessions}
                onChange={(e) => form.setData('concessions', e.target.value)}
            />
            <Input
                aria-label="Other changes"
                placeholder="Other changes"
                value={form.data.note}
                onChange={(e) => form.setData('note', e.target.value)}
            />
            <Button
                size="sm"
                type="submit"
                className="justify-self-start"
                disabled={form.processing}
            >
                Draft the counter-offer
            </Button>
            {(form.errors as Record<string, string>).counter && (
                <span className="text-destructive md:col-span-4">
                    {(form.errors as Record<string, string>).counter}
                </span>
            )}
        </form>
    );
}

function SummaryForm({
    base,
    summary: s,
}: {
    base: string;
    summary: NonNullable<Props['summary']>;
}) {
    const summary = useForm({ en: s.en, es: s.es ?? '', share: s.shared });

    return (
        <form
            className="grid gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                summary.post(`${base}/summary/save`, { preserveScroll: true });
            }}
        >
            <label className="grid gap-1">
                <span className="text-muted-foreground">
                    English{s.ai ? ' (written by Deal-IQ, check it)' : ''}
                </span>
                <textarea
                    className="min-h-24 rounded border bg-background p-2"
                    value={summary.data.en}
                    onChange={(e) => summary.setData('en', e.target.value)}
                />
            </label>
            <label className="grid gap-1">
                <span className="text-muted-foreground">Spanish</span>
                <textarea
                    className="min-h-24 rounded border bg-background p-2"
                    value={summary.data.es}
                    onChange={(e) => summary.setData('es', e.target.value)}
                />
            </label>
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    checked={summary.data.share}
                    onChange={(e) => summary.setData('share', e.target.checked)}
                />
                Show the sellers in their deal portal
            </label>
            {(summary.errors as Record<string, string>).summary && (
                <span className="text-destructive">
                    {(summary.errors as Record<string, string>).summary}
                </span>
            )}
            <Button
                type="submit"
                size="sm"
                className="justify-self-start"
                disabled={summary.processing}
            >
                Save
            </Button>
        </form>
    );
}

/** Offers on a listing: compare, run rounds fairly, counter, accept, and explain it to the seller (specs/ai/02, DQ4). */
export default function Offers(p: Props) {
    const base = `/deals/${p.deal.ulid}/offers`;
    const file = useRef<HTMLInputElement>(null);
    const [editing, setEditing] = useState<string | null>(null);
    const [adding, setAdding] = useState(false);
    const notice = useForm({
        kind: 'highest_best',
        message: `We have received multiple offers on ${p.deal.street}. Please send your highest and best offer by the deadline below. All buyers' agents are receiving this same message at the same time.`,
        deadline: '',
    });
    const sheet = useForm({
        listing_percent: String(p.sheet.listing_percent),
        title_percent: String(p.sheet.title_percent),
        payoff: p.sheet.payoff_cents ? String(p.sheet.payoff_cents / 100) : '',
        other: p.sheet.other_cents ? String(p.sheet.other_cents / 100) : '',
    });
    const active = p.offers.filter((o) => open(o.status));

    return (
        <>
            <Head title={`Offers: ${p.deal.street}`} />
            <div className="flex flex-col gap-4 p-4 text-sm md:p-6">
                <PageHeader
                    title={`Offers on ${p.deal.street}`}
                    description={`Listed at ${usd(p.deal.list_price_cents)}. Best net to the seller first; figures are worked out exactly, never by AI.`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={`/deals/${p.deal.ulid}`}>
                                Back to the deal
                            </Link>
                        </Button>
                    }
                />
                {p.packet && (
                    <p role="status" className="rounded-md border p-3">
                        Draft ready:{' '}
                        <Link
                            className="underline"
                            href={`/forms/packets/${p.packet}`}
                        >
                            check it and send it for signature
                        </Link>
                        .
                    </p>
                )}
                {!p.deal.locked && (
                    <section className="flex flex-wrap items-center gap-2 rounded-lg border p-4">
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `${base}/link`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {p.hasLink
                                ? 'New offer link (the old one stops working)'
                                : 'Get an offer link for buyers’ agents'}
                        </Button>
                        <input
                            ref={file}
                            type="file"
                            className="hidden"
                            accept="application/pdf,image/*"
                            aria-label="Offer file"
                            onChange={(e) => {
                                const f = e.target.files?.[0];
                                if (f) {
                                    router.post(
                                        `${base}/upload`,
                                        { file: f },
                                        {
                                            forceFormData: true,
                                            preserveScroll: true,
                                        },
                                    );
                                }
                            }}
                        />
                        <Button
                            variant="outline"
                            onClick={() => file.current?.click()}
                        >
                            Upload an offer (Deal-IQ reads it)
                        </Button>
                        <Button
                            variant="ghost"
                            onClick={() => setAdding(!adding)}
                        >
                            Type one in
                        </Button>
                        {p.link && (
                            <span className="flex w-full flex-wrap items-center gap-2">
                                <Input
                                    readOnly
                                    value={p.link}
                                    aria-label="Offer link"
                                    className="max-w-xl"
                                    onFocus={(e) => e.currentTarget.select()}
                                />
                                <span className="text-muted-foreground">
                                    Share it with buyers&apos; agents (for
                                    example in agent-only remarks). It is shown
                                    once.
                                </span>
                            </span>
                        )}
                    </section>
                )}
                {adding && (
                    <section className="rounded-lg border p-4">
                        <OfferForm
                            action={base}
                            options={p.options}
                            submitLabel="Add the offer"
                            onDone={() => setAdding(false)}
                        />
                    </section>
                )}
                {p.offers.length === 0 ? (
                    <p className="text-muted-foreground">No offers yet.</p>
                ) : (
                    <section
                        className="overflow-x-auto rounded-lg border"
                        aria-label="Offer comparison"
                    >
                        <table className="w-full min-w-[56rem] text-left">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="p-2">Offer</th>
                                    <th className="p-2">Price</th>
                                    <th className="p-2">Net to seller</th>
                                    <th className="p-2">Financing</th>
                                    <th className="p-2">Closing</th>
                                    <th className="p-2">Contingencies</th>
                                    <th className="p-2">Likely to close</th>
                                    <th className="p-2">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y align-top">
                                {p.offers.map((o) => (
                                    <tr
                                        key={o.ulid}
                                        className={
                                            open(o.status)
                                                ? ''
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        <td className="p-2">
                                            <strong>{o.label}</strong>
                                            {o.round > 1 && (
                                                <span> · round {o.round}</span>
                                            )}
                                            <br />
                                            {o.agent_name ?? 'Agent not given'}
                                            {o.brokerage
                                                ? `, ${o.brokerage}`
                                                : ''}
                                            {o.has_file && (
                                                <>
                                                    <br />
                                                    <a
                                                        className="underline"
                                                        href={`${base}/${o.ulid}/file`}
                                                    >
                                                        The offer
                                                    </a>
                                                </>
                                            )}
                                        </td>
                                        <td className="p-2">
                                            {usd(o.effective_cents)}
                                            {o.escalates && (
                                                <span className="block text-xs text-muted-foreground">
                                                    Escalates from{' '}
                                                    {usd(o.price_cents ?? 0)} up
                                                    to{' '}
                                                    {usd(
                                                        o.escalation_cap_cents ??
                                                            0,
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-2">
                                            <strong>
                                                {usd(o.net.net_cents)}
                                            </strong>
                                            <details>
                                                <summary className="cursor-pointer text-xs">
                                                    How
                                                </summary>
                                                <ul className="text-xs">
                                                    <li>
                                                        Listing side: −
                                                        {usd(
                                                            o.net.listing_cents,
                                                        )}
                                                    </li>
                                                    <li>
                                                        Title and closing: −
                                                        {usd(o.net.title_cents)}
                                                    </li>
                                                    <li>
                                                        Concessions: −
                                                        {usd(
                                                            o.net
                                                                .concessions_cents,
                                                        )}
                                                    </li>
                                                    <li>
                                                        Buyer&apos;s agent: −
                                                        {usd(
                                                            o.net
                                                                .buyer_agent_cents,
                                                        )}
                                                    </li>
                                                    <li>
                                                        Payoff and other: −
                                                        {usd(
                                                            o.net.payoff_cents +
                                                                o.net
                                                                    .other_cents,
                                                        )}
                                                    </li>
                                                </ul>
                                            </details>
                                        </td>
                                        <td className="p-2">
                                            {o.financing}
                                            {o.down_percent
                                                ? `, ${o.down_percent}% down`
                                                : ''}
                                            <span className="block text-xs">
                                                {o.proof}
                                            </span>
                                        </td>
                                        <td className="p-2">
                                            {o.closing_date ?? '—'}
                                            {o.leaseback_days ? (
                                                <span className="block text-xs">
                                                    Leaseback {o.leaseback_days}{' '}
                                                    days
                                                </span>
                                            ) : null}
                                        </td>
                                        <td className="p-2">
                                            {o.contingencies.length
                                                ? o.contingencies.join(', ')
                                                : 'None'}
                                            {o.option_days ? (
                                                <span className="block text-xs">
                                                    {o.option_days} option days
                                                </span>
                                            ) : null}
                                        </td>
                                        <td className="p-2">
                                            <Badge
                                                variant={
                                                    o.certainty.grade ===
                                                    'Strong'
                                                        ? 'default'
                                                        : o.certainty.grade ===
                                                            'Fair'
                                                          ? 'secondary'
                                                          : 'outline'
                                                }
                                            >
                                                {o.certainty.grade} (
                                                {o.certainty.score})
                                            </Badge>
                                            <ul className="text-xs">
                                                {o.certainty.reasons.map(
                                                    (r) => (
                                                        <li key={r}>{r}</li>
                                                    ),
                                                )}
                                            </ul>
                                        </td>
                                        <td className="p-2">
                                            <span className="capitalize">
                                                {o.status}
                                            </span>
                                            {!p.deal.locked &&
                                                open(o.status) && (
                                                    <span className="mt-1 flex flex-wrap gap-1">
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setEditing(
                                                                    editing ===
                                                                        o.ulid
                                                                        ? null
                                                                        : o.ulid,
                                                                )
                                                            }
                                                        >
                                                            Terms and counter
                                                        </Button>
                                                        {o.status !==
                                                            'shortlisted' && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    router.post(
                                                                        `${base}/${o.ulid}/status`,
                                                                        {
                                                                            status: 'shortlisted',
                                                                        },
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Shortlist
                                                            </Button>
                                                        )}
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                router.post(
                                                                    `${base}/${o.ulid}/status`,
                                                                    {
                                                                        status: 'declined',
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Decline
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    `${base}/${o.ulid}/accept`,
                                                                )
                                                            }
                                                        >
                                                            Accept
                                                        </Button>
                                                    </span>
                                                )}
                                            {o.packet && (
                                                <Link
                                                    className="block underline"
                                                    href={`/forms/packets/${o.packet}`}
                                                >
                                                    Counter-offer draft
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                )}
                {p.offers
                    .filter((o) => o.ulid === editing)
                    .map((o) => (
                        <section
                            key={o.ulid}
                            className="grid gap-4 rounded-lg border p-4"
                        >
                            <p className="font-medium">{o.label}: terms</p>
                            {Object.keys(o.read).length > 0 && (
                                <details>
                                    <summary className="cursor-pointer">
                                        What Deal-IQ read from the offer (check
                                        it)
                                    </summary>
                                    <ul className="mt-1 text-xs">
                                        {Object.entries(o.read).map(
                                            ([k, r]) => (
                                                <li key={k}>
                                                    {k.replaceAll('_', ' ')}:{' '}
                                                    {r.value} (page {r.page}: “
                                                    {r.quote}”)
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </details>
                            )}
                            <OfferForm
                                action={`${base}/${o.ulid}`}
                                options={p.options}
                                initial={{
                                    ...o,
                                    financing: keyOf(
                                        p.options.financing,
                                        o.financing,
                                    ),
                                    proof: keyOf(p.options.proof, o.proof),
                                    contingencies: o.contingencies.map((c) =>
                                        keyOf(p.options.contingencies, c),
                                    ),
                                }}
                                submitLabel="Save the terms"
                            />
                            <div className="grid gap-2">
                                <p className="font-medium">Counter-offer</p>
                                <Counter base={base} offer={o} />
                            </div>
                        </section>
                    ))}
                {active.length > 1 && !p.deal.locked && (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <p className="font-medium">
                            Tell every buyer&apos;s agent at once
                        </p>
                        <p className="text-muted-foreground">
                            Each of the {active.length} agents with an open
                            offer gets these exact words at the same moment,
                            with a private link to revise their offer. The send
                            is logged as evidence of fair treatment.
                            {p.deadline &&
                                ` Current deadline: ${when(p.deadline)}.`}
                        </p>
                        <form
                            className="grid gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                notice.post(`${base}/notify`, {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <span className="flex flex-wrap gap-3">
                                <label className="flex items-center gap-1">
                                    <input
                                        type="radio"
                                        checked={
                                            notice.data.kind === 'highest_best'
                                        }
                                        onChange={() =>
                                            notice.setData(
                                                'kind',
                                                'highest_best',
                                            )
                                        }
                                    />
                                    Highest and best (with a deadline)
                                </label>
                                <label className="flex items-center gap-1">
                                    <input
                                        type="radio"
                                        checked={
                                            notice.data.kind === 'multiple'
                                        }
                                        onChange={() =>
                                            notice.setData('kind', 'multiple')
                                        }
                                    />
                                    Multiple offers received
                                </label>
                            </span>
                            <textarea
                                className="min-h-20 rounded border bg-background p-2"
                                aria-label="Message"
                                value={notice.data.message}
                                onChange={(e) =>
                                    notice.setData('message', e.target.value)
                                }
                            />
                            <Input
                                type="datetime-local"
                                aria-label="Deadline"
                                className="max-w-xs"
                                value={notice.data.deadline}
                                onChange={(e) =>
                                    notice.setData('deadline', e.target.value)
                                }
                            />
                            {(notice.errors.deadline ||
                                notice.errors.message) && (
                                <span className="text-destructive">
                                    {notice.errors.deadline ??
                                        notice.errors.message}
                                </span>
                            )}
                            <Button
                                type="submit"
                                size="sm"
                                className="justify-self-start"
                                disabled={notice.processing}
                            >
                                Send to all {active.length}
                            </Button>
                        </form>
                    </section>
                )}
                {p.notices.length > 0 && (
                    <section className="grid gap-1 rounded-lg border p-4">
                        <p className="font-medium">
                            Sent to buyers&apos; agents
                        </p>
                        {p.notices.map((n, i) => (
                            <details key={i}>
                                <summary className="cursor-pointer">
                                    {when(n.at)}: {n.kind.replace('_', ' ')} to{' '}
                                    {n.to} agent(s)
                                    {n.deadline
                                        ? `, deadline ${when(n.deadline)}`
                                        : ''}
                                </summary>
                                <p className="mt-1">{n.message}</p>
                                <p className="text-xs text-muted-foreground">
                                    {n.recipients
                                        .map((r) => `${r.offer} (${r.email})`)
                                        .join(', ')}
                                </p>
                            </details>
                        ))}
                    </section>
                )}
                {p.canSeeCommission && (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <p className="font-medium">
                            The seller&apos;s costs (for net to seller)
                        </p>
                        <form
                            className="grid gap-2 md:grid-cols-5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                sheet.post(`${base}/sheet`, {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <label className="grid gap-1">
                                <span className="text-muted-foreground">
                                    Listing side (%)
                                </span>
                                <Input
                                    type="number"
                                    step="0.01"
                                    value={sheet.data.listing_percent}
                                    onChange={(e) =>
                                        sheet.setData(
                                            'listing_percent',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1">
                                <span className="text-muted-foreground">
                                    Title and closing (%)
                                </span>
                                <Input
                                    type="number"
                                    step="0.01"
                                    value={sheet.data.title_percent}
                                    onChange={(e) =>
                                        sheet.setData(
                                            'title_percent',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1">
                                <span className="text-muted-foreground">
                                    Mortgage payoff ($)
                                </span>
                                <Input
                                    type="number"
                                    value={sheet.data.payoff}
                                    onChange={(e) =>
                                        sheet.setData('payoff', e.target.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1">
                                <span className="text-muted-foreground">
                                    Other costs ($)
                                </span>
                                <Input
                                    type="number"
                                    value={sheet.data.other}
                                    onChange={(e) =>
                                        sheet.setData('other', e.target.value)
                                    }
                                />
                            </label>
                            <Button
                                type="submit"
                                size="sm"
                                className="self-end justify-self-start"
                                disabled={sheet.processing}
                            >
                                Save
                            </Button>
                        </form>
                    </section>
                )}
                {active.length > 0 && (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <p className="font-medium">For the seller</p>
                        <p className="text-muted-foreground">
                            Deal-IQ writes the comparison in plain words
                            (English and Spanish) from the figures above,
                            without buyers&apos; names. Edit it, add your
                            advice, then share it in the sellers&apos; deal
                            portal.
                        </p>
                        <Button
                            size="sm"
                            variant="outline"
                            className="justify-self-start"
                            onClick={() =>
                                router.post(
                                    `${base}/summary`,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => router.reload(),
                                    },
                                )
                            }
                        >
                            {p.summary
                                ? 'Write it again from the latest offers'
                                : 'Write the summary'}
                        </Button>
                        {p.summary && (
                            <SummaryForm
                                key={p.summary.at}
                                base={base}
                                summary={p.summary}
                            />
                        )}
                    </section>
                )}
            </div>
        </>
    );
}

Offers.layout = { breadcrumbs: [{ title: 'Deals', href: '/deals' }] };
