import {
    ClosingPanel,
    type ClosingProps,
} from '@/components/deals/closing-panel';
import { WinCardPanel, type WinCard } from '@/components/deals/win-card';
import {
    MoneyDesk,
    type Cda,
    type Deposit,
    type Referral,
} from '@/components/deals/money-desk';
import { Head, router, useForm } from '@inertiajs/react';
import { CameraIcon, UploadIcon } from 'lucide-react';
import { useRef, useState } from 'react';

import {
    DocIqPanel,
    DocRow,
    type DealDocument,
    type Fact,
} from '@/components/deals/doc-iq';
import {
    DealIqPanel,
    type DealIqProps,
} from '@/components/deals/deal-iq-panel';
import { DealPay, type PayOverrides } from '@/components/deals/deal-pay';
import {
    PlaybookPanel,
    type PlaybookProps,
} from '@/components/deals/playbook-panel';
import { PortalPanel, type PortalProps } from '@/components/deals/portal-panel';
import { KeyDates, type KeyDate } from '@/components/deals/key-dates';
import {
    CompliancePanel,
    type Compliance,
} from '@/components/deals/compliance-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';
import { WhyCanISee } from '@/components/why-can-i-see';
import { CustomFieldsPanel } from '@/components/custom-fields-panel';
import type { CustomFieldValue } from '@/components/custom-fields-panel';

type Item = {
    ulid: string;
    label: string;
    required: boolean;
    stage: string;
    status: string;
    fix_reason: string | null;
    added_reason: string | null;
    overdue: boolean;
    documents: DealDocument[];
};
type Props = {
    deal: {
        ulid: string;
        street: string;
        city: string | null;
        state_code: string;
        postal_code: string | null;
        type: string;
        status: string;
        price_cents: number;
        gci_cents: number | null;
        commission_hidden: boolean;
        mls_number: string | null;
        contract_date: string | null;
        closing_date: string | null;
        closed_on: string | null;
        locked: boolean;
        imported_from: string | null;
        money_status: string | null;
        money_note: string | null;
        pay_overrides: PayOverrides;
        agent_paid_outside: boolean;
        cancel_reason: string | null;
        agent: string | null;
    };
    parties: {
        role: string;
        name: string;
        company: string | null;
        email: string | null;
        phone: string | null;
    }[];
    items: Item[];
    missing: { label: string; status: string }[];
    dates: KeyDate[];
    unsorted: DealDocument[];
    emailIn: string;
    facts: Fact[];
    compliance: Compliance;
    reviewer: boolean;
    reasons: string[];
    risk: { score: number; level: string; reasons: string[] };
    customFields: CustomFieldValue[];
    dealIq: DealIqProps;
    portal: PortalProps;
    playbook: PlaybookProps;
    closing: ClosingProps;
};

const statusLabel: Record<string, string> = {
    missing: 'Needed',
    field_missing: 'Fill in under More details',
    submitted: 'Waiting for review',
    needs_fix: 'Needs a fix',
    approved: 'Approved',
    waived: 'Not needed',
};

function ChecklistItem({
    item,
    deal,
    reviewer,
    reasons,
}: {
    item: Item;
    deal: Props['deal'];
    reviewer: boolean;
    reasons: string[];
}) {
    const file = useRef<HTMLInputElement>(null);
    const photo = useRef<HTMLInputElement>(null);
    const [reason, setReason] = useState('');
    const send = (f: File | undefined, via: string) =>
        f &&
        router.post(
            `/deals/${deal.ulid}/documents`,
            { file: f, item: item.ulid, via },
            { forceFormData: true, preserveScroll: true },
        );

    return (
        <li id={`item-${item.ulid}`} className="grid gap-2 p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-medium">
                    {item.label}
                    {!item.required && (
                        <span className="font-normal text-muted-foreground">
                            {' '}
                            (if applicable)
                        </span>
                    )}
                </span>
                <span className="flex gap-1">
                    {item.overdue && (
                        <Badge variant="destructive">Overdue for review</Badge>
                    )}
                    <Badge
                        variant={
                            item.status === 'approved'
                                ? 'secondary'
                                : item.status === 'needs_fix'
                                  ? 'destructive'
                                  : 'outline'
                        }
                    >
                        {statusLabel[item.status]}
                    </Badge>
                </span>
            </div>
            {item.fix_reason && item.status === 'needs_fix' && (
                <p className="text-destructive">Fix: {item.fix_reason}</p>
            )}
            {item.added_reason && (
                <p className="text-xs text-muted-foreground">
                    {item.added_reason}
                </p>
            )}
            {item.documents.map((d) => (
                <DocRow key={d.ulid} doc={d} dealUlid={deal.ulid} />
            ))}
            {!deal.locked && (
                <span className="flex flex-wrap gap-2">
                    <input
                        ref={file}
                        type="file"
                        className="hidden"
                        aria-label={`Upload ${item.label}`}
                        onChange={(e) => send(e.target.files?.[0], 'upload')}
                    />
                    <input
                        ref={photo}
                        type="file"
                        accept="image/*"
                        capture="environment"
                        className="hidden"
                        aria-label={`Photo of ${item.label}`}
                        onChange={(e) => send(e.target.files?.[0], 'photo')}
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => file.current?.click()}
                    >
                        <UploadIcon aria-hidden="true" />
                        Upload
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => photo.current?.click()}
                    >
                        <CameraIcon aria-hidden="true" />
                        Photo
                    </Button>
                    {reviewer &&
                        ['submitted', 'needs_fix'].includes(item.status) && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/deals/items/${item.ulid}/review`,
                                        { action: 'approve' },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Approve
                            </Button>
                        )}
                </span>
            )}
            {reviewer &&
                !deal.locked &&
                item.status !== 'approved' &&
                item.status !== 'waived' && (
                    <span className="flex flex-wrap gap-2">
                        <Input
                            aria-label="What to fix, or why not needed"
                            list={`reasons-${item.ulid}`}
                            placeholder="What to fix, or why it's not needed"
                            className="max-w-md"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                        <datalist id={`reasons-${item.ulid}`}>
                            {reasons.map((r) => (
                                <option key={r} value={r} />
                            ))}
                        </datalist>
                        {item.status === 'submitted' && (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={reason.length < 5}
                                onClick={() =>
                                    router.post(
                                        `/deals/items/${item.ulid}/review`,
                                        { action: 'fix', reason },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Request fix
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={reason.length < 5}
                            onClick={() =>
                                router.post(
                                    `/deals/items/${item.ulid}/review`,
                                    { action: 'waive', reason },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Not needed
                        </Button>
                    </span>
                )}
        </li>
    );
}

/** One deal: overview, status, checklist with uploads and review, parties. */
export default function ShowDeal({
    deal,
    parties,
    items,
    missing,
    customFields,
    dealIq,
    portal,
    playbook,
    closing,
    dates,
    unsorted,
    emailIn,
    facts,
    compliance,
    reviewer,
    reasons,
    risk,
    deposits,
    referrals,
    winCard,
    cdas,
    titles,
    can,
}: Props & {
    deposits: Deposit[];
    referrals: Referral[];
    winCard: WinCard | null;
    cdas: Cda[];
    titles: { ulid: string; name: string }[];
    can: {
        trust: boolean;
        approveCda: boolean;
        export: boolean;
        managePay?: boolean;
    };
}) {
    const contract = useForm({
        status: 'under_contract',
        price: deal.price_cents ? String(deal.price_cents / 100) : '',
        gci: '',
        contract_date: '',
        closing_date: '',
    });
    const [reason, setReason] = useState('');

    return (
        <>
            <Head title={deal.street} />
            <div className="flex max-w-4xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title={deal.street}
                    description={`${[deal.city, deal.state_code, deal.postal_code].filter(Boolean).join(', ')} · ${deal.agent ?? ''}${deal.locked ? ' · Closed and locked' : ''}${deal.imported_from ? ` · Brought over from ${deal.imported_from}` : ''}`}
                />
                {risk.level !== 'low' && (
                    <p
                        role="status"
                        className={`rounded-lg border p-3 ${risk.level === 'high' ? 'border-destructive text-destructive' : ''}`}
                    >
                        <strong>Risk {risk.score} of 100</strong> ({risk.level}
                        ): {risk.reasons.join(', ')}. Fix these and it drops.
                    </p>
                )}
                <WhyCanISee url={`/deals/${deal.ulid}/why`} />
                <DealIqPanel
                    dealUlid={deal.ulid}
                    locked={deal.locked}
                    iq={dealIq}
                    parties={parties}
                />
                <PlaybookPanel
                    dealUlid={deal.ulid}
                    locked={deal.locked}
                    playbook={playbook}
                />
                <ClosingPanel
                    dealUlid={deal.ulid}
                    locked={deal.locked}
                    closing={closing}
                />
                <PortalPanel
                    dealUlid={deal.ulid}
                    portal={portal}
                    parties={parties}
                    roles={dealIq.roles}
                />
                <section className="grid gap-2 rounded-lg border p-4 text-sm md:grid-cols-4">
                    <span>
                        Status:{' '}
                        <strong className="capitalize">
                            {deal.status.replace('_', ' ')}
                        </strong>
                    </span>
                    <span>Price: {usd(deal.price_cents)}</span>
                    <span>
                        Commission (GCI):{' '}
                        {deal.commission_hidden
                            ? 'Hidden for your role'
                            : deal.gci_cents
                              ? usd(deal.gci_cents)
                              : '—'}
                    </span>
                    <span>
                        Closing: {deal.closed_on ?? deal.closing_date ?? '—'}
                    </span>
                    {deal.cancel_reason && (
                        <span className="md:col-span-4">
                            Fell through: {deal.cancel_reason}
                        </span>
                    )}
                </section>
                <CustomFieldsPanel
                    fields={customFields}
                    action={`/deals/${deal.ulid}/fields`}
                    canEdit={!deal.locked}
                />
                {winCard && <WinCardPanel ulid={deal.ulid} win={winCard} />}
                {deal.status === 'closed' && can.managePay && (
                    <DealPay
                        ulid={deal.ulid}
                        status={deal.money_status}
                        note={deal.money_note}
                        overrides={deal.pay_overrides}
                        importedFrom={deal.imported_from}
                        paidOutside={deal.agent_paid_outside}
                    />
                )}
                {deal.status === 'pre_contract' && (
                    <form
                        className="flex flex-wrap items-end gap-2 rounded-lg border p-4 text-sm"
                        onSubmit={(e) => {
                            e.preventDefault();
                            contract.post(`/deals/${deal.ulid}/status`, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <span className="w-full font-medium">
                            Under contract
                        </span>
                        <Input
                            aria-label="Contract price ($)"
                            placeholder="Price $"
                            type="number"
                            className="w-36"
                            value={contract.data.price}
                            onChange={(e) =>
                                contract.setData('price', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Commission (GCI $)"
                            placeholder="GCI $"
                            type="number"
                            className="w-32"
                            value={contract.data.gci}
                            onChange={(e) =>
                                contract.setData('gci', e.target.value)
                            }
                        />
                        <label className="grid gap-1">
                            Contract date
                            <Input
                                type="date"
                                value={contract.data.contract_date}
                                onChange={(e) =>
                                    contract.setData(
                                        'contract_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            Expected closing
                            <Input
                                type="date"
                                value={contract.data.closing_date}
                                onChange={(e) =>
                                    contract.setData(
                                        'closing_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                        <Button type="submit">Mark under contract</Button>
                        {Object.values(contract.errors).length > 0 && (
                            <p role="alert" className="w-full text-destructive">
                                {Object.values(contract.errors)[0]}
                            </p>
                        )}
                    </form>
                )}
                {['pre_contract', 'under_contract'].includes(deal.status) && (
                    <section className="flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm">
                        {deal.status === 'under_contract' && reviewer && (
                            <Button
                                disabled={missing.length > 0}
                                onClick={() =>
                                    router.post(
                                        `/deals/${deal.ulid}/status`,
                                        { status: 'closed' },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Close the deal
                            </Button>
                        )}
                        {missing.length > 0 && (
                            <span className="text-muted-foreground">
                                Still needed before closing:{' '}
                                {missing.map((m) => m.label).join(', ')}
                            </span>
                        )}
                        <Input
                            aria-label="Why it fell through"
                            placeholder="Why it fell through"
                            className="max-w-xs"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                        <Button
                            variant="outline"
                            disabled={reason.length < 5}
                            onClick={() =>
                                router.post(
                                    `/deals/${deal.ulid}/status`,
                                    { status: 'fell_through', reason },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Fell through
                        </Button>
                    </section>
                )}
                <CompliancePanel
                    deal={deal.ulid}
                    compliance={compliance}
                    reviewer={reviewer}
                    locked={deal.locked}
                />
                <EmailIn address={emailIn} />
                <DocIqPanel
                    deal={deal.ulid}
                    locked={deal.locked}
                    unsorted={unsorted}
                    facts={facts}
                />
                <KeyDates deal={deal.ulid} dates={dates} />
                <section className="grid gap-2">
                    <h2 className="font-semibold" data-tour="checklist">
                        Checklist
                    </h2>
                    <ul className="divide-y rounded-lg border">
                        {items.map((i) => (
                            <ChecklistItem
                                key={i.ulid}
                                item={i}
                                deal={deal}
                                reviewer={reviewer}
                                reasons={reasons}
                            />
                        ))}
                    </ul>
                </section>
                <section className="grid gap-2">
                    <h2 className="font-semibold">People on this deal</h2>
                    <ul className="divide-y rounded-lg border text-sm">
                        {parties.length === 0 && (
                            <li className="p-3 text-muted-foreground">
                                No one added yet.
                            </li>
                        )}
                        {parties.map((p, i) => (
                            <li key={i} className="p-3">
                                <span className="capitalize">
                                    {p.role.replace('_', ' ')}
                                </span>
                                : {p.name}
                                {p.company ? ` (${p.company})` : ''}{' '}
                                {p.email ?? ''} {p.phone ?? ''}
                            </li>
                        ))}
                    </ul>
                </section>
                <div id="money-desk" />
                <MoneyDesk
                    deal={deal}
                    deposits={deposits}
                    referrals={referrals}
                    cdas={cdas}
                    titles={titles}
                    reviewer={reviewer}
                    can={can}
                />
            </div>
        </>
    );
}

ShowDeal.layout = { breadcrumbs: [{ title: 'Deals', href: '/deals' }] };

/** LM1: the deal's own email address; attachments land in the unsorted documents. */
function EmailIn({ address }: { address: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <section className="flex flex-wrap items-center gap-2 rounded-lg border p-3 text-sm">
            <span className="font-medium">Email documents to this deal:</span>
            <code className="rounded bg-muted px-2 py-1 break-all">
                {address}
            </code>
            <Button
                size="sm"
                variant="outline"
                onClick={() => {
                    void navigator.clipboard
                        ?.writeText(address)
                        .then(() => setCopied(true));
                }}
            >
                {copied ? 'Copied' : 'Copy'}
            </Button>
            <span className="w-full text-muted-foreground">
                Give it to the lender, title company or the other agent. PDFs
                and photos arrive under &ldquo;Unsorted&rdquo; for you to file.
            </span>
        </section>
    );
}
