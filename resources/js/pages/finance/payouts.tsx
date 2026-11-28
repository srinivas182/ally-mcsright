import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { RailSetup, type RailInfo } from '@/components/payouts/rail-setup';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Line = {
    name: string;
    type: string;
    commission_cents: number;
    revenue_share_cents: number;
    referral_cents: number;
    amount_cents: number;
    method: string;
    status: string;
    reason: string | null;
    check: number | null;
    /** CM2: what the payment is made of, by deal and level (newest runs only). */
    items?:
        | {
              kind: string;
              deal: string;
              level: number | null;
              from: string | null;
              cents: number;
          }[]
        | null;
};

const itemText = (it: NonNullable<Line['items']>[number]) =>
    it.kind === 'commission'
        ? `Commission: ${it.deal}`
        : it.kind === 'bonus'
          ? `Recruit's first closing bonus: ${it.deal}`
          : `${it.kind === 'reversal' ? 'Reversed revenue share' : 'Revenue share'}, level ${it.level} from ${it.from ?? 'an agent'}: ${it.deal}`;

function Breakdown({ items }: { items: NonNullable<Line['items']> }) {
    const [open, setOpen] = useState(false);
    if (items.length === 0) {
        return null;
    }

    return (
        <div className="mt-1">
            <button
                type="button"
                className="text-xs text-primary underline-offset-2 hover:underline"
                aria-expanded={open}
                onClick={() => setOpen(!open)}
            >
                {open ? 'Hide' : 'Show'} what this pays for ({items.length})
            </button>
            {open && (
                <ul className="mt-1 grid gap-0.5 text-xs">
                    {items.map((it, i) => (
                        <li key={i} className="flex justify-between gap-3">
                            <span>{itemText(it)}</span>
                            <span className="tabular-nums">
                                {usd(it.cents)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
type Run = {
    ulid: string;
    kind?: string;
    rail?: string;
    failed?: number;
    status: string;
    pay_on: string;
    same_day: boolean;
    total_cents: number;
    approved_by: string | null;
    sent_at: string | null;
    held?: { deal: string; street: string; reason: string }[];
    lines: Line[];
};
type Props = {
    runs: Run[];
    settings: {
        company_name: string | null;
        company_id: string | null;
        bank_name: string | null;
        odfi_last4: string | null;
        operating_last4: string | null;
        next_check_number: number;
    };
    canApprove: boolean;
    canChangeSettings: boolean;
    rail: RailInfo;
    instant: {
        enabled: boolean;
        flat_cents: number;
        percent: string;
        min_cents: number;
        limits: { max_flat_cents: number; max_percent: string };
    };
    auto: { enabled: boolean; limit_cents: number; same_day: boolean };
};

const method: Record<string, string> = {
    ach: 'Direct deposit',
    check: 'Check',
    none: '—',
};

function RunCard({ run, canApprove }: { run: Run; canApprove: boolean }) {
    const opts = { preserveScroll: true };
    const [override, setOverride] = useState('');

    return (
        <section className="grid gap-2 rounded-lg border p-4 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-semibold">
                    Pay on {new Date(run.pay_on).toLocaleDateString()}
                    {run.same_day ? ' (same day)' : ''} · {usd(run.total_cents)}
                </span>
                <span className="flex gap-1">
                    {run.kind === 'instant' && <Badge>Get paid now</Badge>}
                    {run.kind === 'auto' && <Badge>Automatic</Badge>}
                    <Badge
                        variant={
                            run.status === 'sent' ? 'secondary' : 'outline'
                        }
                        className="capitalize"
                    >
                        {run.status}
                    </Badge>
                </span>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full">
                    <thead className="text-left text-muted-foreground">
                        <tr>
                            <th className="p-1">Payee</th>
                            <th className="p-1">Commission</th>
                            <th className="p-1">Revenue share</th>
                            <th className="p-1">Referral</th>
                            <th className="p-1">Total</th>
                            <th className="p-1">How</th>
                        </tr>
                    </thead>
                    <tbody>
                        {run.lines.map((l, i) => (
                            <tr
                                key={i}
                                className={`border-t ${l.status === 'skipped' ? 'text-muted-foreground' : ''}`}
                            >
                                <td className="p-1">
                                    {l.name}
                                    {l.reason ? (
                                        <span className="block text-xs">
                                            Skipped: {l.reason}
                                        </span>
                                    ) : null}
                                    {l.items && <Breakdown items={l.items} />}
                                </td>
                                <td className="p-1">
                                    {l.commission_cents
                                        ? usd(l.commission_cents)
                                        : ''}
                                </td>
                                <td className="p-1">
                                    {l.revenue_share_cents
                                        ? usd(l.revenue_share_cents)
                                        : ''}
                                </td>
                                <td className="p-1">
                                    {l.referral_cents
                                        ? usd(l.referral_cents)
                                        : ''}
                                </td>
                                <td className="p-1 font-medium">
                                    {usd(l.amount_cents)}
                                </td>
                                <td className="p-1">
                                    {method[l.method]}
                                    {l.check ? ` #${l.check}` : ''}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {run.status === 'draft' && (run.held ?? []).length > 0 && (
                <div
                    role="alert"
                    className="grid gap-1 rounded border border-red-300 bg-red-50 p-2 text-sm text-red-900"
                >
                    <p className="font-medium">
                        Deal-IQ is holding payouts on these deals:
                    </p>
                    {(run.held ?? []).map((h) => (
                        <p key={h.deal}>
                            <a className="underline" href={`/deals/${h.deal}`}>
                                {h.street}
                            </a>
                            : {h.reason}
                        </p>
                    ))}
                    {canApprove && (
                        <Input
                            aria-label="Why you are paying anyway"
                            placeholder="Why you are paying anyway (recorded in the audit log)"
                            className="bg-background"
                            value={override}
                            onChange={(e) => setOverride(e.target.value)}
                        />
                    )}
                </div>
            )}
            <span className="flex flex-wrap gap-2">
                {run.status === 'draft' && canApprove && (
                    <Button
                        size="sm"
                        variant={
                            (run.held ?? []).length > 0
                                ? 'destructive'
                                : 'default'
                        }
                        disabled={
                            (run.held ?? []).length > 0 && override.length < 10
                        }
                        onClick={() =>
                            router.post(
                                `/finance/payouts/${run.ulid}/approve`,
                                override ? { override } : {},
                                opts,
                            )
                        }
                    >
                        {(run.held ?? []).length > 0
                            ? 'Approve anyway'
                            : 'Approve'}
                    </Button>
                )}
                {run.status === 'approved' && (
                    <Button
                        size="sm"
                        onClick={() =>
                            router.post(
                                `/finance/payouts/${run.ulid}/send`,
                                {},
                                opts,
                            )
                        }
                    >
                        Send payouts
                    </Button>
                )}
                {(run.status === 'draft' || run.status === 'approved') && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            router.post(
                                `/finance/payouts/${run.ulid}/cancel`,
                                {},
                                opts,
                            )
                        }
                    >
                        Cancel run
                    </Button>
                )}
                {run.status === 'sent' && !!run.failed && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                `/finance/payouts/${run.ulid}/retry`,
                                {},
                                opts,
                            )
                        }
                    >
                        Retry {run.failed} payment(s) not accepted
                    </Button>
                )}
                {run.status === 'sent' && (
                    <>
                        {run.lines.some(
                            (l) =>
                                l.method === 'ach' && l.status === 'included',
                        ) && (
                            <a
                                className="underline"
                                href={`/finance/payouts/${run.ulid}/files/ach`}
                            >
                                Bank file (ACH)
                            </a>
                        )}
                        {run.lines.some(
                            (l) =>
                                l.method === 'check' && l.status === 'included',
                        ) && (
                            <a
                                className="underline"
                                href={`/finance/payouts/${run.ulid}/files/checks`}
                            >
                                Checks (PDF)
                            </a>
                        )}
                        {run.lines.some(
                            (l) =>
                                l.method === 'check' && l.status === 'included',
                        ) && (
                            <a
                                className="underline"
                                href={`/finance/payouts/${run.ulid}/files/positive-pay`}
                            >
                                Positive pay file
                            </a>
                        )}
                    </>
                )}
            </span>
            {run.status === 'draft' && (
                <p className="text-xs text-muted-foreground">
                    Someone who isn't paid in this run, and hasn't changed a
                    payee's bank details in the last 30 days, must approve it.
                </p>
            )}
        </section>
    );
}

type Tab = 'pay' | 'how' | 'auto';

/**
 * Payout runs: preview, approve, send as a bank file and checks. RightAlly never holds the money. FX7: three tabs an
 * owner can find their way around: Pay agents (what needs doing now, the open run and past runs), How we pay (the
 * bank and how money leaves it, as numbered steps with their state) and Automatic payouts (pay on match, get paid
 * now), each setting with a plain explanation.
 */
export default function Payouts({
    runs,
    settings,
    canApprove,
    canChangeSettings,
    rail,
    instant,
    auto,
}: Props) {
    const initial = (() => {
        try {
            const t = new URLSearchParams(window.location.search).get('tab');

            return t === 'how' || t === 'auto' ? t : 'pay';
        } catch {
            return 'pay';
        }
    })() as Tab;
    const [tab, setTab] = useState<Tab>(initial);
    const pick = (t: Tab) => {
        setTab(t);
        try {
            const u = new URL(window.location.href);
            u.searchParams.set('tab', t);
            window.history.replaceState(window.history.state, '', u);
        } catch {
            // the address is only a convenience
        }
    };
    const build = useForm({
        pay_on: new Date().toISOString().slice(0, 10),
        same_day: false,
    });
    const form = useForm({
        company_name: settings.company_name ?? '',
        company_id: settings.company_id ?? '',
        odfi_routing: '',
        bank_name: settings.bank_name ?? '',
        operating_account: '',
        next_check_number: String(settings.next_check_number),
    });
    const open = runs.filter(
        (r) => r.status === 'draft' || r.status === 'approved',
    );
    const past = runs.filter(
        (r) => r.status !== 'draft' && r.status !== 'approved',
    );
    const waiting = open.filter((r) => r.status === 'draft');
    const ready = open.filter((r) => r.status === 'approved');
    const monthStart = new Date();
    monthStart.setDate(1);
    const paidThisMonth = past
        .filter((r) => r.status === 'sent' && new Date(r.pay_on) >= monthStart)
        .reduce((t, r) => t + r.total_cents, 0);
    const bankReady = Boolean(settings.company_id);
    const tabs: [Tab, string, string | null][] = [
        [
            'pay',
            'Pay agents',
            waiting.length > 0 ? String(waiting.length) : null,
        ],
        ['how', 'How we pay', bankReady ? null : 'Set up'],
        ['auto', 'Automatic payouts', null],
    ];

    return (
        <>
            <Head title="Payouts" />
            <div className="flex max-w-5xl flex-col gap-5 p-4 md:p-6">
                <PageHeader
                    title="Payouts"
                    description="Pay agents and referral partners from the brokerage's own bank account. RightAlly prepares and checks; it never holds the money."
                />
                <div
                    role="tablist"
                    aria-label="Payouts"
                    className="flex gap-1 border-b"
                >
                    {tabs.map(([key, label, badge]) => (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={tab === key}
                            className={`-mb-px flex items-center gap-2 border-b-2 px-4 py-2.5 text-[15px] font-medium transition-colors ${
                                tab === key
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground'
                            }`}
                            onClick={() => pick(key)}
                        >
                            {label}
                            {badge && (
                                <Badge
                                    variant={
                                        key === 'pay'
                                            ? 'destructive'
                                            : 'outline'
                                    }
                                    className="px-1.5"
                                >
                                    {badge}
                                </Badge>
                            )}
                        </button>
                    ))}
                </div>

                {tab === 'pay' && (
                    <div role="tabpanel" className="grid gap-5">
                        {!bankReady && canChangeSettings && (
                            <p className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                                Add your bank details before your first payout,
                                so the bank file and checks are right.
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => pick('how')}
                                >
                                    Set up how we pay
                                </Button>
                            </p>
                        )}
                        <dl className="grid gap-3 sm:grid-cols-3">
                            {[
                                [
                                    'Waiting for approval',
                                    waiting.reduce(
                                        (t, r) => t + r.total_cents,
                                        0,
                                    ),
                                    `${waiting.length} ${waiting.length === 1 ? 'run' : 'runs'}`,
                                ],
                                [
                                    'Approved, ready to send',
                                    ready.reduce(
                                        (t, r) => t + r.total_cents,
                                        0,
                                    ),
                                    `${ready.length} ${ready.length === 1 ? 'run' : 'runs'}`,
                                ],
                                [
                                    'Paid this month',
                                    paidThisMonth,
                                    'sent to agents',
                                ],
                            ].map(([label, cents, note]) => (
                                <div
                                    key={label as string}
                                    className="grid gap-1 rounded-xl border bg-card p-4"
                                >
                                    <dt className="text-sm text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd className="text-2xl font-semibold tabular-nums">
                                        {usd(cents as number)}
                                    </dd>
                                    <dd className="text-xs text-muted-foreground">
                                        {note}
                                    </dd>
                                </div>
                            ))}
                        </dl>

                        {open.length === 0 && (
                            <form
                                className="grid gap-3 rounded-xl border bg-card p-5 text-sm"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    build.post('/finance/payouts', {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <div>
                                    <h2 className="text-base font-semibold">
                                        Prepare the next payout
                                    </h2>
                                    <p className="text-muted-foreground">
                                        We gather everything agents and partners
                                        are owed from closed deals whose money
                                        has arrived. Nothing is paid until it is
                                        approved.
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-end gap-4">
                                    <label className="grid gap-1.5 font-medium">
                                        Pay on
                                        <Input
                                            type="date"
                                            className="w-44"
                                            value={build.data.pay_on}
                                            onChange={(e) =>
                                                build.setData(
                                                    'pay_on',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                    <label className="flex h-9 items-center gap-2">
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            checked={build.data.same_day}
                                            onChange={(e) =>
                                                build.setData(
                                                    'same_day',
                                                    e.target.checked,
                                                )
                                            }
                                        />
                                        Same-day ACH (if your bank offers it)
                                    </label>
                                    <Button type="submit">
                                        Prepare payout
                                    </Button>
                                </div>
                                {Object.values(build.errors)[0] && (
                                    <p
                                        role="alert"
                                        className="text-destructive"
                                    >
                                        {Object.values(build.errors)[0]}
                                    </p>
                                )}
                            </form>
                        )}

                        {open.length > 0 && (
                            <section className="grid gap-3">
                                <h2 className="text-base font-semibold">
                                    Needs your attention
                                </h2>
                                {open.map((r) => (
                                    <RunCard
                                        key={r.ulid}
                                        run={r}
                                        canApprove={canApprove}
                                    />
                                ))}
                            </section>
                        )}

                        <section className="grid gap-3">
                            <h2 className="text-base font-semibold">
                                Past payouts
                            </h2>
                            {past.length === 0 ? (
                                <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                                    Payouts you send show here, with their bank
                                    files and checks.
                                </p>
                            ) : (
                                past.map((r) => (
                                    <RunCard
                                        key={r.ulid}
                                        run={r}
                                        canApprove={canApprove}
                                    />
                                ))
                            )}
                        </section>
                    </div>
                )}

                {tab === 'how' && (
                    <div role="tabpanel" className="grid gap-5">
                        {canChangeSettings ? (
                            <>
                                <div className="grid gap-2">
                                    <h2 className="flex items-center gap-2 text-base font-semibold">
                                        <span className="grid size-7 place-items-center rounded-full bg-primary text-sm text-primary-foreground">
                                            1
                                        </span>
                                        How money leaves your bank
                                    </h2>
                                    <RailSetup info={rail} />
                                </div>
                                <section className="grid gap-3 rounded-xl border bg-card p-5 text-sm">
                                    <h2 className="flex flex-wrap items-center gap-2 text-base font-semibold">
                                        <span className="grid size-7 place-items-center rounded-full bg-primary text-sm text-primary-foreground">
                                            2
                                        </span>
                                        Your bank details
                                        <Badge
                                            variant={
                                                bankReady
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {bankReady
                                                ? 'Set up'
                                                : 'Not set up yet'}
                                        </Badge>
                                    </h2>
                                    <p className="text-muted-foreground">
                                        {bankReady
                                            ? `ACH company ${settings.company_name} (${settings.company_id}) at ${settings.bank_name}, routing ending ${settings.odfi_last4}${settings.operating_last4 ? `, operating account ending ${settings.operating_last4}` : ''}. Next check #${settings.next_check_number}.`
                                            : 'Your bank gives you these when you set up ACH payments (sometimes called "ACH origination"). Ask your banker for the company ID if you do not have it.'}
                                    </p>
                                    <form
                                        className="grid gap-4 sm:grid-cols-2"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            form.post(
                                                '/finance/payout-settings',
                                                {
                                                    preserveScroll: true,
                                                },
                                            );
                                        }}
                                    >
                                        {(
                                            [
                                                [
                                                    'company_name',
                                                    'Company name, as your bank has it',
                                                    'Up to 16 characters',
                                                    16,
                                                    'text',
                                                ],
                                                [
                                                    'company_id',
                                                    'ACH company ID',
                                                    '10 characters, from your bank',
                                                    10,
                                                    'text',
                                                ],
                                                [
                                                    'bank_name',
                                                    'Bank name',
                                                    '',
                                                    23,
                                                    'text',
                                                ],
                                                [
                                                    'odfi_routing',
                                                    'Bank routing number',
                                                    bankReady
                                                        ? `Leave empty to keep the one ending ${settings.odfi_last4}`
                                                        : '9 digits',
                                                    9,
                                                    'numeric',
                                                ],
                                                [
                                                    'operating_account',
                                                    'Operating account number',
                                                    settings.operating_last4
                                                        ? `Leave empty to keep the one ending ${settings.operating_last4}`
                                                        : 'For positive pay on checks',
                                                    17,
                                                    'numeric',
                                                ],
                                                [
                                                    'next_check_number',
                                                    'Next check number',
                                                    '',
                                                    9,
                                                    'numeric',
                                                ],
                                            ] as const
                                        ).map(
                                            ([key, label, hint, max, mode]) => (
                                                <label
                                                    key={key}
                                                    className="grid content-start gap-1.5 font-medium"
                                                >
                                                    {label}
                                                    <Input
                                                        className="h-10"
                                                        inputMode={
                                                            mode === 'numeric'
                                                                ? 'numeric'
                                                                : undefined
                                                        }
                                                        maxLength={max}
                                                        value={form.data[key]}
                                                        onChange={(e) =>
                                                            form.setData(
                                                                key,
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                    {hint && (
                                                        <span className="text-xs font-normal text-muted-foreground">
                                                            {hint}
                                                        </span>
                                                    )}
                                                </label>
                                            ),
                                        )}
                                        {Object.values(form.errors)[0] && (
                                            <p
                                                role="alert"
                                                className="text-destructive sm:col-span-2"
                                            >
                                                {Object.values(form.errors)[0]}
                                            </p>
                                        )}
                                        <Button
                                            type="submit"
                                            className="justify-self-start"
                                            disabled={form.processing}
                                        >
                                            Save bank details
                                        </Button>
                                    </form>
                                </section>
                            </>
                        ) : (
                            <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                                {bankReady
                                    ? `Payouts go from ${settings.bank_name}. Only the owner can change how the brokerage pays.`
                                    : 'The owner has not set up how the brokerage pays yet.'}
                            </p>
                        )}
                    </div>
                )}

                {tab === 'auto' && (
                    <div role="tabpanel" className="grid gap-5">
                        {canApprove ? <AutoSettings auto={auto} /> : null}
                        {canChangeSettings ? (
                            <InstantSettings instant={instant} />
                        ) : null}
                        {!canApprove && !canChangeSettings && (
                            <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                                Only the owner can change automatic payouts.
                            </p>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

Payouts.layout = {
    breadcrumbs: [{ title: 'Payouts', href: '/finance/payouts' }],
};

function Toggle({
    checked,
    onChange,
    label,
}: {
    checked: boolean;
    onChange: (v: boolean) => void;
    label: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors ${checked ? 'bg-primary' : 'bg-muted-foreground/30'}`}
        >
            <span
                className={`inline-block size-5 rounded-full bg-white shadow transition-transform ${checked ? 'translate-x-5' : 'translate-x-0.5'}`}
            />
        </button>
    );
}

function SettingCard({
    title,
    body,
    checked,
    onToggle,
    children,
    onSave,
    saving,
    error,
}: {
    title: string;
    body: string;
    checked: boolean;
    onToggle: (v: boolean) => void;
    children: React.ReactNode;
    onSave: () => void;
    saving: boolean;
    error?: string;
}) {
    return (
        <form
            className="grid gap-4 rounded-xl border bg-card p-5 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                onSave();
            }}
        >
            <div className="flex items-start gap-4">
                <div className="grid flex-1 gap-1">
                    <h2 className="flex items-center gap-2 text-base font-semibold">
                        {title}
                        <Badge variant={checked ? 'secondary' : 'outline'}>
                            {checked ? 'On' : 'Off'}
                        </Badge>
                    </h2>
                    <p className="text-muted-foreground">{body}</p>
                </div>
                <Toggle checked={checked} onChange={onToggle} label={title} />
            </div>
            {checked && (
                <div className="flex flex-wrap items-end gap-4">{children}</div>
            )}
            {error && (
                <p role="alert" className="text-destructive">
                    {error}
                </p>
            )}
            <Button
                type="submit"
                variant="outline"
                className="justify-self-start"
                disabled={saving}
            >
                Save
            </Button>
        </form>
    );
}

function Money({
    label,
    value,
    onChange,
    hint,
    suffix,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    hint?: string;
    suffix?: string;
}) {
    return (
        <label className="grid gap-1.5 font-medium">
            {label}
            <span className="flex items-center gap-1.5">
                {!suffix && <span className="text-muted-foreground">$</span>}
                <Input
                    type="number"
                    min={0}
                    step="0.01"
                    className="h-10 w-32"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
                {suffix && (
                    <span className="text-muted-foreground">{suffix}</span>
                )}
            </span>
            {hint && (
                <span className="text-xs font-normal text-muted-foreground">
                    {hint}
                </span>
            )}
        </label>
    );
}

function InstantSettings({ instant }: { instant: Props['instant'] }) {
    const form = useForm({
        enabled: instant.enabled,
        flat: String(instant.flat_cents / 100),
        percent: instant.percent,
        min: String(instant.min_cents / 100),
    });

    return (
        <SettingCard
            title="Let agents get paid now"
            body={`Agents can ask to be paid as soon as a deal's money arrives, instead of waiting for the next payout. You can charge a small fee for this (your income), up to ${usd(instant.limits.max_flat_cents)} + ${instant.limits.max_percent}%. Set it to zero to make it free.`}
            checked={form.data.enabled}
            onToggle={(v) => form.setData('enabled', v)}
            onSave={() =>
                form.post('/finance/get-paid-now', { preserveScroll: true })
            }
            saving={form.processing}
            error={Object.values(form.errors)[0]}
        >
            <Money
                label="Flat fee"
                value={form.data.flat}
                onChange={(v) => form.setData('flat', v)}
            />
            <Money
                label="Plus"
                value={form.data.percent}
                onChange={(v) => form.setData('percent', v)}
                suffix="% of the payout"
            />
            <Money
                label="Only for payouts from"
                value={form.data.min}
                onChange={(v) => form.setData('min', v)}
                hint="Smaller amounts wait for the normal payout"
            />
        </SettingCard>
    );
}

function AutoSettings({ auto }: { auto: Props['auto'] }) {
    const form = useForm({
        enabled: auto.enabled,
        limit: String(auto.limit_cents / 100),
        same_day: auto.same_day,
    });

    return (
        <SettingCard
            title="Pay agents automatically"
            body="When a deal's money arrives and matches the commission statement (CDA), agents are paid without waiting for you. Anything above your limit still waits for your approval. The 72-hour hold after bank details change, and every other safeguard, still apply."
            checked={form.data.enabled}
            onToggle={(v) => form.setData('enabled', v)}
            onSave={() =>
                form.post('/finance/auto-payouts', { preserveScroll: true })
            }
            saving={form.processing}
            error={Object.values(form.errors)[0]}
        >
            <Money
                label="Pay automatically up to"
                value={form.data.limit}
                onChange={(v) => form.setData('limit', v)}
                hint="Per agent, per payout"
            />
            <label className="flex h-10 items-center gap-2 font-medium">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.same_day}
                    onChange={(e) => form.setData('same_day', e.target.checked)}
                />
                Send the same day (same-day ACH)
            </label>
        </SettingCard>
    );
}
