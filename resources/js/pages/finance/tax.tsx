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
    efile: {
        ready: boolean;
        payer: {
            legal_name: string;
            ein_last4: string;
            street: string;
            city: string;
            state: string;
            zip: string;
            phone: string;
        } | null;
        problems: string[];
        filings: {
            ulid: string;
            year: number;
            status: string;
            records: number;
            total_cents: number;
            message: string | null;
            created_at: string;
        }[];
    };
};

const match: Record<string, string> = {
    pending: 'Not checked yet',
    matched: 'Matches IRS records',
    mismatch: "Name and TIN don't match",
    invalid: 'Invalid TIN',
};

/** Year-end 1099-NEC totals, W-9s and IRS TIN matching. */
export default function Tax({
    year,
    threshold_cents,
    rows,
    referrals,
    efile,
}: Props) {
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
                <EFile year={year} efile={efile} />
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

const filingStatus: Record<string, string> = {
    transmitted: 'With the IRS',
    accepted: 'Accepted by the IRS',
    rejected: 'Rejected: needs a correction',
    failed: 'Not sent',
};

/** AC2: e-file the 1099-NECs with the IRS through the partner, after checking everything is ready. */
function EFile({ year, efile }: { year: number; efile: Props['efile'] }) {
    const payer = useForm({
        legal_name: efile.payer?.legal_name ?? '',
        ein: '',
        street: efile.payer?.street ?? '',
        city: efile.payer?.city ?? '',
        state: efile.payer?.state ?? '',
        zip: efile.payer?.zip ?? '',
        phone: efile.payer?.phone ?? '',
    });
    const file = useForm({ year, mail_copies: false });
    const field = (
        key: keyof typeof payer.data,
        label: string,
        hint?: string,
    ) => (
        <label className="grid gap-1">
            {label}
            <input
                className="rounded-md border bg-background p-2"
                value={payer.data[key]}
                placeholder={hint}
                onChange={(e) => payer.setData(key, e.target.value)}
            />
        </label>
    );

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <h2 className="font-semibold">E-file {year} with the IRS</h2>
            <form
                className="grid gap-2 sm:grid-cols-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    payer.post('/finance/tax/payer', { preserveScroll: true });
                }}
            >
                <p className="text-muted-foreground sm:col-span-3">
                    The brokerage as payer, exactly as the IRS knows it.
                </p>
                {field('legal_name', 'Legal name')}
                {field(
                    'ein',
                    'EIN',
                    efile.payer
                        ? `On file, ending ${efile.payer.ein_last4}`
                        : '9 digits',
                )}
                {field('phone', 'Phone')}
                {field('street', 'Street')}
                {field('city', 'City')}
                <div className="grid grid-cols-2 gap-2">
                    {field('state', 'State')}
                    {field('zip', 'ZIP')}
                </div>
                <div className="sm:col-span-3">
                    <Button variant="outline" disabled={payer.processing}>
                        Save payer details
                    </Button>
                </div>
                {Object.values(payer.errors).length > 0 && (
                    <p className="text-destructive sm:col-span-3">
                        {Object.values(payer.errors)[0]}
                    </p>
                )}
            </form>
            {efile.problems.length > 0 ? (
                <ul className="grid gap-1 rounded-md bg-amber-50 p-3 text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                    {efile.problems.map((p) => (
                        <li key={p}>{p}</li>
                    ))}
                </ul>
            ) : (
                <form
                    className="flex flex-wrap items-center gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        file.post('/finance/tax/efile', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={file.data.mail_copies}
                            onChange={(e) =>
                                file.setData('mail_copies', e.target.checked)
                            }
                        />
                        Also mail each person their copy
                    </label>
                    <Button disabled={file.processing}>
                        File {year} 1099-NECs
                    </Button>
                    {Object.values(file.errors).length > 0 && (
                        <p className="text-destructive">
                            {Object.values(file.errors)[0]}
                        </p>
                    )}
                </form>
            )}
            {efile.filings.length > 0 && (
                <ul className="grid gap-1">
                    {efile.filings.map((f) => (
                        <li
                            key={f.ulid}
                            className="flex flex-wrap items-center gap-2 rounded border p-2"
                        >
                            <span>{f.year}</span>
                            <span>
                                {f.records} returns · {usd(f.total_cents)}
                            </span>
                            <Badge
                                variant={
                                    f.status === 'accepted'
                                        ? 'default'
                                        : f.status === 'transmitted'
                                          ? 'secondary'
                                          : 'destructive'
                                }
                            >
                                {filingStatus[f.status] ?? f.status}
                            </Badge>
                            <span className="text-muted-foreground">
                                {f.message}
                            </span>
                            {f.status === 'transmitted' && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="ml-auto"
                                    onClick={() =>
                                        router.post(
                                            `/finance/tax/efile/${f.ulid}/refresh`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Check the IRS answer
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
