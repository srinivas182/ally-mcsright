import { Head, router, useForm } from '@inertiajs/react';

import { W9Form } from '@/components/payouts/w9-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Row = {
    payee: string;
    kind: string;
    paid_cents: number;
    reportable: boolean;
    w9: {
        legal_name: string;
        tin_last4: string;
        tin_type: string;
        match_status: string;
    } | null;
};
type Props = {
    year: number;
    threshold_cents: number;
    rows: Row[];
    referrals: { ulid: string; brokerage: string; agent_name: string }[];
};

const match: Record<string, string> = {
    pending: 'Not checked yet',
    matched: 'Matches IRS records',
    mismatch: "Name and TIN don't match",
    invalid: 'Invalid TIN',
};

/** Year-end 1099-NEC totals, W-9s and IRS TIN matching. */
export default function Tax({ year, threshold_cents, rows, referrals }: Props) {
    const upload = useForm<{ file: File | null }>({ file: null });

    return (
        <>
            <Head title="Tax (1099)" />
            <div className="flex max-w-5xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title={`1099-NEC for ${year}`}
                    description={`Payees paid ${usd(threshold_cents)} or more in ${year} get a 1099-NEC (the IRS threshold for that year).`}
                />
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <select
                        aria-label="Year"
                        className="rounded border bg-background px-2 py-1.5"
                        value={year}
                        onChange={(e) =>
                            router.get('/finance/tax', { year: e.target.value })
                        }
                    >
                        {[0, 1, 2].map((d) => (
                            <option
                                key={d}
                                value={new Date().getFullYear() - d}
                            >
                                {new Date().getFullYear() - d}
                            </option>
                        ))}
                    </select>
                    <a
                        className="underline"
                        href={`/finance/tax/export?year=${year}`}
                    >
                        Export for filing (CSV)
                    </a>
                    <a className="underline" href="/finance/tax/tin-file">
                        IRS bulk TIN matching file
                    </a>
                    <form
                        className="flex items-center gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            upload.post('/finance/tax/tin-results', {
                                forceFormData: true,
                            });
                        }}
                    >
                        <input
                            aria-label="IRS results file"
                            type="file"
                            onChange={(e) =>
                                upload.setData(
                                    'file',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={!upload.data.file}
                        >
                            Import IRS results
                        </Button>
                    </form>
                </div>
                <ul className="divide-y rounded-lg border text-sm">
                    {rows.length === 0 && (
                        <li className="p-3 text-muted-foreground">
                            No payments in {year}.
                        </li>
                    )}
                    {rows.map((r, i) => (
                        <li
                            key={i}
                            className="flex flex-wrap items-center justify-between gap-2 p-3"
                        >
                            <span>
                                {r.payee}{' '}
                                <span className="text-muted-foreground capitalize">
                                    ({r.kind})
                                </span>
                            </span>
                            <span className="flex flex-wrap items-center gap-2">
                                {usd(r.paid_cents)}
                                {r.reportable ? (
                                    <Badge>1099 due</Badge>
                                ) : (
                                    <Badge variant="outline">
                                        Under threshold
                                    </Badge>
                                )}
                                {r.w9 ? (
                                    <Badge
                                        variant={
                                            r.w9.match_status === 'matched'
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                    >
                                        W-9 {r.w9.tin_type.toUpperCase()} …
                                        {r.w9.tin_last4} ·{' '}
                                        {match[r.w9.match_status]}
                                    </Badge>
                                ) : (
                                    <Badge variant="destructive">
                                        W-9 missing
                                    </Badge>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
                {referrals.length > 0 && (
                    <section className="grid gap-2 rounded-lg border p-4 text-sm">
                        <h2 className="font-semibold">
                            W-9s still needed from referral payees
                        </h2>
                        {referrals.map((r) => (
                            <details
                                key={r.ulid}
                                className="rounded-md bg-muted/30 p-2"
                            >
                                <summary>
                                    {r.agent_name}, {r.brokerage}
                                </summary>
                                <div className="mt-2">
                                    <W9Form referral={r.ulid} />
                                </div>
                            </details>
                        ))}
                    </section>
                )}
            </div>
        </>
    );
}

Tax.layout = { breadcrumbs: [{ title: 'Tax (1099)', href: '/finance/tax' }] };
