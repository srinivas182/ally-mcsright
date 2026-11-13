import { Head, router, useForm } from '@inertiajs/react';

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
};
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
            <span className="flex flex-wrap gap-2">
                {run.status === 'draft' && canApprove && (
                    <Button
                        size="sm"
                        onClick={() =>
                            router.post(
                                `/finance/payouts/${run.ulid}/approve`,
                                {},
                                opts,
                            )
                        }
                    >
                        Approve
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

/** Payout runs: preview, approve, send as a bank file and checks. RightAlly never holds the money. */
export default function Payouts({
    runs,
    settings,
    canApprove,
    canChangeSettings,
    rail,
    instant,
    auto,
}: Props) {
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
    const open = runs.find(
        (r) => r.status === 'draft' || r.status === 'approved',
    );

    return (
        <>
            <Head title="Payouts" />
            <div className="flex max-w-5xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Payouts"
                    description="Pay agents and referral partners from the brokerage's own bank account. RightAlly prepares and checks; it never holds the money."
                />
                {!open && (
                    <form
                        className="flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm"
                        onSubmit={(e) => {
                            e.preventDefault();
                            build.post('/finance/payouts', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        Prepare a payout run for
                        <Input
                            aria-label="Pay on"
                            type="date"
                            className="w-40"
                            value={build.data.pay_on}
                            onChange={(e) =>
                                build.setData('pay_on', e.target.value)
                            }
                        />
                        <label className="flex items-center gap-1">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={build.data.same_day}
                                onChange={(e) =>
                                    build.setData('same_day', e.target.checked)
                                }
                            />{' '}
                            Same-day ACH (if your bank offers it)
                        </label>
                        <Button type="submit">Prepare</Button>
                        {Object.values(build.errors)[0] && (
                            <p role="alert" className="w-full text-destructive">
                                {Object.values(build.errors)[0]}
                            </p>
                        )}
                    </form>
                )}
                {canApprove && <AutoSettings auto={auto} />}
                {canChangeSettings && <InstantSettings instant={instant} />}
                {canChangeSettings && <RailSetup info={rail} />}
                {runs.map((r) => (
                    <RunCard key={r.ulid} run={r} canApprove={canApprove} />
                ))}
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">Payout settings</h2>
                    <p className="text-muted-foreground">
                        {settings.company_id
                            ? `ACH company ${settings.company_name} (${settings.company_id}) at ${settings.bank_name}, routing ending ${settings.odfi_last4}`
                            : 'Not set up yet.'}
                        {settings.operating_last4
                            ? ` · operating account ending ${settings.operating_last4}`
                            : ''}{' '}
                        · next check #{settings.next_check_number}
                    </p>
                    {canChangeSettings && (
                        <form
                            className="grid gap-2 sm:grid-cols-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/finance/payout-settings', {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <Input
                                aria-label="ACH company name"
                                placeholder="Company name (as your bank has it)"
                                maxLength={16}
                                value={form.data.company_name}
                                onChange={(e) =>
                                    form.setData('company_name', e.target.value)
                                }
                            />
                            <Input
                                aria-label="ACH company ID"
                                placeholder="ACH company ID (10 characters)"
                                maxLength={10}
                                value={form.data.company_id}
                                onChange={(e) =>
                                    form.setData('company_id', e.target.value)
                                }
                            />
                            <Input
                                aria-label="Bank name"
                                placeholder="Bank name"
                                maxLength={23}
                                value={form.data.bank_name}
                                onChange={(e) =>
                                    form.setData('bank_name', e.target.value)
                                }
                            />
                            <Input
                                aria-label="Bank routing number"
                                placeholder="Bank routing number"
                                inputMode="numeric"
                                value={form.data.odfi_routing}
                                onChange={(e) =>
                                    form.setData('odfi_routing', e.target.value)
                                }
                            />
                            <Input
                                aria-label="Operating account number"
                                placeholder="Operating account (for positive pay)"
                                inputMode="numeric"
                                value={form.data.operating_account}
                                onChange={(e) =>
                                    form.setData(
                                        'operating_account',
                                        e.target.value,
                                    )
                                }
                            />
                            <Input
                                aria-label="Next check number"
                                type="number"
                                value={form.data.next_check_number}
                                onChange={(e) =>
                                    form.setData(
                                        'next_check_number',
                                        e.target.value,
                                    )
                                }
                            />
                            {Object.values(form.errors)[0] && (
                                <p
                                    role="alert"
                                    className="text-destructive sm:col-span-3"
                                >
                                    {Object.values(form.errors)[0]}
                                </p>
                            )}
                            <Button
                                type="submit"
                                variant="outline"
                                className="justify-self-start"
                            >
                                Save settings
                            </Button>
                        </form>
                    )}
                </section>
            </div>
        </>
    );
}

Payouts.layout = {
    breadcrumbs: [{ title: 'Payouts', href: '/finance/payouts' }],
};

function InstantSettings({ instant }: { instant: Props['instant'] }) {
    const form = useForm({
        enabled: instant.enabled,
        flat: String(instant.flat_cents / 100),
        percent: instant.percent,
        min: String(instant.min_cents / 100),
    });

    return (
        <form
            className="flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/finance/get-paid-now', { preserveScroll: true });
            }}
        >
            <label className="flex items-center gap-1">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.enabled}
                    onChange={(e) => form.setData('enabled', e.target.checked)}
                />{' '}
                Let agents get paid now
            </label>
            · fee $
            <Input
                aria-label="Flat fee ($)"
                type="number"
                min={0}
                step="0.01"
                className="w-24"
                value={form.data.flat}
                onChange={(e) => form.setData('flat', e.target.value)}
            />
            +{' '}
            <Input
                aria-label="Percent fee"
                type="number"
                min={0}
                step="0.01"
                className="w-20"
                value={form.data.percent}
                onChange={(e) => form.setData('percent', e.target.value)}
            />
            % · from $
            <Input
                aria-label="Minimum ($)"
                type="number"
                min={1}
                className="w-24"
                value={form.data.min}
                onChange={(e) => form.setData('min', e.target.value)}
            />
            <Button type="submit" size="sm" variant="outline">
                Save
            </Button>
            <span className="w-full text-xs text-muted-foreground">
                Up to {usd(instant.limits.max_flat_cents)} +{' '}
                {instant.limits.max_percent}% (RightAlly's limit). The fee is
                the brokerage's income; set it to zero to make it free.
            </span>
            {Object.values(form.errors)[0] && (
                <p role="alert" className="w-full text-destructive">
                    {Object.values(form.errors)[0]}
                </p>
            )}
        </form>
    );
}

function AutoSettings({ auto }: { auto: Props['auto'] }) {
    const form = useForm({
        enabled: auto.enabled,
        limit: String(auto.limit_cents / 100),
        same_day: auto.same_day,
    });

    return (
        <form
            className="flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/finance/auto-payouts', { preserveScroll: true });
            }}
        >
            <label className="flex items-center gap-1">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.enabled}
                    onChange={(e) => form.setData('enabled', e.target.checked)}
                />{' '}
                Pay agents automatically when a deal's money matches the CDA
            </label>
            · up to $
            <Input
                aria-label="Automatic limit per payee ($)"
                type="number"
                min={0}
                className="w-28"
                value={form.data.limit}
                onChange={(e) => form.setData('limit', e.target.value)}
            />{' '}
            each
            <label className="flex items-center gap-1">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.same_day}
                    onChange={(e) => form.setData('same_day', e.target.checked)}
                />{' '}
                same day
            </label>
            <Button type="submit" size="sm" variant="outline">
                Save
            </Button>
            <span className="w-full text-xs text-muted-foreground">
                Larger payouts wait for approval. The 72-hour bank-change hold
                and every other safeguard still apply. Owners only.
            </span>
            {Object.values(form.errors)[0] && (
                <p role="alert" className="w-full text-destructive">
                    {Object.values(form.errors)[0]}
                </p>
            )}
        </form>
    );
}
