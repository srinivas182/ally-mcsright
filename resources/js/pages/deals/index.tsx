import { Head, Link, useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

import { VoiceButton } from '@/components/crm/voice-button';
import {
    AtRisk,
    type AlertPrefs,
    type AtRiskItem,
} from '@/components/deals/at-risk';
import { NewDealForm, typeLabel } from '@/components/deals/new-deal-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { StatCard } from '@/components/ui/stat-card';
import { usd } from '@/lib/cents';

type Deal = {
    ulid: string;
    street: string;
    city: string | null;
    type: string;
    status: string;
    price_cents: number;
    closing_date: string | null;
    agent: string | null;
    missing: number;
};
type Forecast = {
    buckets: Record<
        string,
        {
            days: number;
            deals: number;
            company_cents: number;
            gci_cents: number;
        }
    >;
    agents: { name: string; deals: number; company_cents: number }[];
} | null;
type Props = {
    deals: Deal[];
    canCreateFor: { ulid: string; name: string }[];
    forecast?: Forecast;
    atRisk: AtRiskItem[];
    alerts: AlertPrefs;
    canBringOverFiles?: boolean;
    dealIqEmail?: string | null;
};

const views: [string, string][] = [
    ['under_contract', 'Under contract'],
    ['closed', 'Closed'],
    ['fell_through', 'Fell through'],
];

const inDays = (date: string | null, days: number) => {
    if (!date) {
        return false;
    }
    const t = new Date(date).getTime();
    const now = Date.now();

    return t >= now - 86_400_000 && t <= now + days * 86_400_000;
};

/** The fast ways to start a deal: from the signed contract, by describing it, by email, or by hand. */
function StartDeal({
    canCreateFor,
    dealIqEmail,
    contact,
}: {
    canCreateFor: Props['canCreateFor'];
    dealIqEmail: string | null;
    contact: string;
}) {
    const contractFile = useRef<HTMLInputElement>(null);
    const intake = useForm<{ file: File | null }>({ file: null });
    const describe = useForm({ text: '' });
    const [byHand, setByHand] = useState(contact !== '');

    return (
        <section
            className="grid gap-3 rounded-xl border bg-card p-4 text-sm"
            aria-label="Start a deal with Deal-IQ"
            id="start"
            data-tour="deal-start"
        >
            <div>
                <p className="text-base font-semibold">
                    Start a transaction from the signed contract
                </p>
                <p className="text-muted-foreground">
                    Deal-IQ reads it and fills in the people, price and every
                    date for you to check.
                </p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <input
                    ref={contractFile}
                    type="file"
                    className="hidden"
                    accept="application/pdf,image/*"
                    capture="environment"
                    aria-label="Contract file or photo"
                    onChange={(e) => {
                        const f = e.target.files?.[0];
                        if (f) {
                            intake.transform(() => ({ file: f }));
                            intake.post('/deals/intake', {
                                forceFormData: true,
                            });
                        }
                    }}
                />
                <Button
                    disabled={intake.processing}
                    onClick={() => contractFile.current?.click()}
                >
                    {intake.processing
                        ? 'Uploading…'
                        : 'Upload the contract (file or photo)'}
                </Button>
                <span className="text-muted-foreground">or describe it</span>
                <form
                    className="flex min-w-64 flex-1 flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        describe.post('/deals/intake/text');
                    }}
                >
                    <Input
                        aria-label="Describe the new deal"
                        className="min-w-56 flex-1"
                        placeholder='e.g. "Buyer deal, the Garcias, 412 Oak St Austin TX, $450k, closing Dec 18"'
                        value={describe.data.text}
                        onChange={(e) =>
                            describe.setData('text', e.target.value)
                        }
                    />
                    <VoiceButton
                        onText={(t) => describe.setData('text', t)}
                        label="Describe it out loud"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={
                            describe.processing ||
                            describe.data.text.trim() === ''
                        }
                    >
                        Set it up
                    </Button>
                </form>
            </div>
            {(intake.errors.file || describe.errors.text) && (
                <p role="alert" className="text-destructive">
                    {intake.errors.file ?? describe.errors.text}
                </p>
            )}
            {dealIqEmail && (
                <p>
                    Or forward the signed contract by email to{' '}
                    <strong className="break-all">{dealIqEmail}</strong>{' '}
                    <button
                        type="button"
                        className="underline"
                        onClick={() =>
                            void navigator.clipboard?.writeText(dealIqEmail)
                        }
                    >
                        Copy
                    </button>
                    . Only emails you send from your RightAlly email address are
                    read.
                </p>
            )}
            <div>
                <Button
                    variant="link"
                    className="h-auto p-0"
                    aria-expanded={byHand}
                    onClick={() => setByHand(!byHand)}
                >
                    No contract yet? Type the deal in yourself
                </Button>
                {byHand && (
                    <div className="mt-2 grid gap-2">
                        <p className="text-muted-foreground">
                            Deals without a signed contract go to{' '}
                            <Link href="/pipeline" className="underline">
                                CRM › Pipeline
                            </Link>{' '}
                            until the contract is signed.
                        </p>
                        <NewDealForm
                            canCreateFor={canCreateFor}
                            contact={contact}
                        />
                    </div>
                )}
            </div>
        </section>
    );
}

/**
 * PG1: All transactions. Deals from the signed contract onwards: the numbers that matter at the top, what is at
 * risk today, then every transaction in one list you can filter and search. Deals not under contract yet live in
 * CRM › Pipeline.
 */
export default function Deals({
    deals,
    canCreateFor,
    forecast,
    atRisk,
    alerts,
    canBringOverFiles = false,
    dealIqEmail = null,
}: Props) {
    const params = new URLSearchParams(
        typeof window !== 'undefined' ? window.location.search : '',
    );
    const contact = params.get('contact') ?? '';
    const [starting, setStarting] = useState(
        contact !== '' || deals.length === 0,
    );
    const [view, setView] = useState('under_contract');
    const [q, setQ] = useState('');
    const active = deals.filter((d) => d.status === 'under_contract');
    const pipeline = deals.filter((d) => d.status === 'pre_contract').length;
    const shown = useMemo(() => {
        const term = q.trim().toLowerCase();

        return deals
            .filter((d) => d.status === view)
            .filter(
                (d) =>
                    term === '' ||
                    `${d.street} ${d.city ?? ''} ${d.agent ?? ''}`
                        .toLowerCase()
                        .includes(term),
            )
            .sort((a, b) =>
                (a.closing_date ?? '9999').localeCompare(
                    b.closing_date ?? '9999',
                ),
            );
    }, [deals, view, q]);
    const soon = forecast
        ? Object.values(forecast.buckets).find((b) => b.days === 30)
        : undefined;

    return (
        <>
            <Head title="All transactions" />
            <div className="flex flex-col gap-5 p-4 md:p-6">
                <PageHeader
                    title="All transactions"
                    description="Every deal from the signed contract to the closing table."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {canBringOverFiles && (
                                <Button variant="outline" asChild>
                                    <Link href="/deals/bring-over-files">
                                        Switch from another app
                                    </Link>
                                </Button>
                            )}
                            <Button
                                aria-expanded={starting}
                                onClick={() => setStarting(!starting)}
                            >
                                New transaction
                            </Button>
                        </div>
                    }
                />
                {starting && (
                    <StartDeal
                        canCreateFor={canCreateFor}
                        dealIqEmail={dealIqEmail}
                        contact={contact}
                    />
                )}
                <section
                    aria-label="Your numbers"
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <StatCard label="Under contract" value={active.length} />
                    <StatCard
                        label="Closing in the next 30 days"
                        value={
                            active.filter((d) => inDays(d.closing_date, 30))
                                .length
                        }
                    />
                    <StatCard
                        label="Files still missing documents"
                        value={active.filter((d) => d.missing > 0).length}
                    />
                    {soon ? (
                        <StatCard
                            label="Company dollar, next 30 days"
                            value={usd(soon.company_cents)}
                        />
                    ) : (
                        <StatCard
                            label="Closed"
                            value={
                                deals.filter((d) => d.status === 'closed')
                                    .length
                            }
                        />
                    )}
                </section>
                <AtRisk items={atRisk} alerts={alerts} />
                {pipeline > 0 && (
                    <p className="rounded-lg bg-muted/50 px-4 py-3 text-sm">
                        {pipeline} {pipeline === 1 ? 'deal is' : 'deals are'}{' '}
                        not under contract yet. They are in{' '}
                        <Link
                            href="/pipeline"
                            className="font-medium underline"
                        >
                            CRM › Pipeline
                        </Link>
                        .
                    </p>
                )}
                <section
                    aria-labelledby="list"
                    className="grid gap-3"
                    data-tour="deal-list"
                >
                    <h2 id="list" className="sr-only">
                        Transactions
                    </h2>
                    <div className="flex flex-wrap items-center gap-2">
                        <div
                            role="tablist"
                            aria-label="Show"
                            className="flex gap-1 rounded-lg bg-muted/60 p-1"
                        >
                            {views.map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={view === key}
                                    onClick={() => setView(key)}
                                    className={`rounded-md px-3 py-1.5 text-sm font-medium ${
                                        view === key
                                            ? 'bg-background shadow-xs'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {label} (
                                    {
                                        deals.filter((d) => d.status === key)
                                            .length
                                    }
                                    )
                                </button>
                            ))}
                        </div>
                        <label className="relative ml-auto w-full sm:w-72">
                            <span className="sr-only">Find a transaction</span>
                            <Search
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                className="h-10 pl-9"
                                placeholder="Find by address or agent"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
                        </label>
                    </div>
                    {shown.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-8 text-center text-muted-foreground">
                            {q
                                ? 'No transaction matches that.'
                                : view === 'under_contract'
                                  ? 'No deals under contract. Press "New transaction" and upload a signed contract to start one.'
                                  : 'Nothing here yet.'}
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border bg-card">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/50 text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">
                                            Property
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Side
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Agent
                                        </th>
                                        <th className="px-4 py-3 text-right font-medium">
                                            Price
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Closing
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            File
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {shown.map((d) => (
                                        <tr
                                            key={d.ulid}
                                            className="border-t hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`/deals/${d.ulid}`}
                                                    className="font-semibold hover:underline"
                                                >
                                                    {d.street}
                                                </Link>
                                                {d.city && (
                                                    <span className="block text-muted-foreground">
                                                        {d.city}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                {typeLabel[d.type] ?? d.type}
                                            </td>
                                            <td className="px-4 py-3">
                                                {d.agent ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {d.price_cents
                                                    ? usd(d.price_cents)
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                {d.closing_date
                                                    ? new Date(
                                                          d.closing_date,
                                                      ).toLocaleDateString()
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                {d.status === 'closed' ? (
                                                    <Badge variant="secondary">
                                                        Closed
                                                    </Badge>
                                                ) : d.missing > 0 ? (
                                                    <Badge variant="outline">
                                                        {d.missing} still needed
                                                    </Badge>
                                                ) : d.status ===
                                                  'fell_through' ? (
                                                    <Badge variant="outline">
                                                        Fell through
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary">
                                                        Complete
                                                    </Badge>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

Deals.layout = {
    breadcrumbs: [{ title: 'All transactions', href: '/deals' }],
};
