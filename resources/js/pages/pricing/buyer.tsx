import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { postJson } from '@/lib/http';

type Result = {
    price_cents: number;
    loan: string;
    down_cents: number;
    loan_cents: number;
    monthly: Record<string, number>;
    monthly_total_cents: number;
    closing_cents: number;
    cash_to_close_cents: number;
    notes: string[];
};
type Sheet = {
    ulid: string;
    title: string;
    inputs: Record<string, number | string>;
    result: Result;
    views: number;
};

const usd = (cents: number) =>
    (cents / 100).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    });
const labels: Record<string, string> = {
    principal_interest: 'Principal and interest',
    taxes: 'Property taxes',
    insurance: 'Home insurance',
    mortgage_insurance: 'Mortgage insurance',
    hoa: 'HOA dues',
};

/** Buyer cost estimate (CM1): monthly payment and cash to close, live as the agent types; save, share or PDF. */
export default function Buyer({
    defaults,
    loans,
    sheet,
    contact,
    shareUrl,
}: {
    defaults: Record<string, number>;
    loans: string[];
    sheet: Sheet | null;
    contact: {
        ulid: string;
        first_name: string;
        last_name: string | null;
    } | null;
    shareUrl: string | null;
}) {
    const i = sheet?.inputs;
    const [f, setF] = useState({
        price: i ? Number(i.price_cents) / 100 : 400000,
        down_pct: i ? Number(i.down_pct) : 20,
        loan: i ? String(i.loan) : 'conventional',
        rate: i ? Number(i.rate) : defaults.interest_rate,
        years: i ? Number(i.years) : 30,
        tax_pct: i ? Number(i.tax_pct) : defaults.property_tax_pct,
        insurance_pct: i ? Number(i.insurance_pct) : defaults.insurance_pct,
        hoa: i ? Number(i.hoa_cents) / 100 : 0,
        closing_pct: i ? Number(i.closing_pct) : defaults.buyer_closing_pct,
        seller_credit: i ? Number(i.seller_credit_cents) / 100 : 0,
        title: sheet?.title ?? '',
    });
    const [r, setR] = useState<Result | null>(sheet?.result ?? null);
    const [problem, setProblem] = useState<string | null>(null);

    useEffect(() => {
        const t = setTimeout(() => {
            postJson<{ result: Result }>('/pricing/buyer/calculate', f)
                .then((res) => {
                    setR(res.result);
                    setProblem(null);
                })
                .catch((e: unknown) =>
                    setProblem(
                        e instanceof Error ? e.message : 'Check the numbers.',
                    ),
                );
        }, 300);
        return () => clearTimeout(t);
    }, [f]);

    const num = (k: keyof typeof f, label: string, step = '1') => (
        <label className="grid gap-1 text-sm">
            {label}
            <Input
                type="number"
                step={step}
                value={f[k]}
                onChange={(e) => setF({ ...f, [k]: Number(e.target.value) })}
            />
        </label>
    );

    return (
        <>
            <Head title="Buyer cost estimate" />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title="Buyer cost estimate"
                    description={
                        contact
                            ? `For ${contact.first_name} ${contact.last_name ?? ''}`
                            : 'Monthly payment and cash to close, to plan with.'
                    }
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/pricing">Back</Link>
                        </Button>
                    }
                />
                <div className="grid gap-4 lg:grid-cols-[1fr_380px]">
                    <div className="grid content-start gap-3 rounded-lg border p-4 sm:grid-cols-2">
                        {num('price', 'Price ($)', '1000')}
                        <label className="grid gap-1 text-sm">
                            Loan type
                            <select
                                className="h-10 rounded-md border bg-background px-3"
                                value={f.loan}
                                onChange={(e) =>
                                    setF({ ...f, loan: e.target.value })
                                }
                            >
                                {loans.map((l) => (
                                    <option key={l} value={l}>
                                        {l === 'fha'
                                            ? 'FHA'
                                            : l === 'va'
                                              ? 'VA'
                                              : 'Conventional'}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {num('down_pct', 'Down payment %', '0.5')}
                        {num('rate', 'Interest rate %', '0.125')}
                        {num('years', 'Years')}
                        {num('tax_pct', 'Property tax % a year', '0.05')}
                        {num('insurance_pct', 'Insurance % a year', '0.05')}
                        {num('hoa', 'HOA dues a month ($)')}
                        {num('closing_pct', 'Closing costs %', '0.1')}
                        {num('seller_credit', 'Seller credit ($)', '500')}
                    </div>
                    <div
                        className="grid content-start gap-3 rounded-lg border p-4"
                        aria-live="polite"
                    >
                        {problem && (
                            <p className="text-sm text-red-700">{problem}</p>
                        )}
                        {r && (
                            <>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Monthly payment
                                    </p>
                                    <p className="text-3xl font-semibold">
                                        {usd(r.monthly_total_cents)}
                                    </p>
                                </div>
                                <ul className="grid gap-1 text-sm">
                                    {Object.entries(r.monthly)
                                        .filter(([, v]) => v > 0)
                                        .map(([k, v]) => (
                                            <li
                                                key={k}
                                                className="flex justify-between"
                                            >
                                                <span>{labels[k] ?? k}</span>
                                                <span>{usd(v)}</span>
                                            </li>
                                        ))}
                                </ul>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Cash to close
                                    </p>
                                    <p className="text-2xl font-semibold">
                                        {usd(r.cash_to_close_cents)}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {usd(r.down_cents)} down +{' '}
                                        {usd(r.closing_cents)} closing costs
                                        {f.seller_credit > 0
                                            ? ' − seller credit'
                                            : ''}
                                    </p>
                                </div>
                                <ul className="list-disc pl-5 text-xs text-muted-foreground">
                                    {r.notes.map((n) => (
                                        <li key={n}>{n}</li>
                                    ))}
                                </ul>
                            </>
                        )}
                        <label className="grid gap-1 text-sm">
                            Name it
                            <Input
                                value={f.title}
                                onChange={(e) =>
                                    setF({ ...f, title: e.target.value })
                                }
                                placeholder="e.g. 3-bed in Round Rock"
                            />
                        </label>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                onClick={() =>
                                    router.post('/pricing/buyer', {
                                        ...f,
                                        sheet: sheet?.ulid,
                                        contact: contact?.ulid,
                                    })
                                }
                            >
                                Save
                            </Button>
                            {sheet && (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/pricing/buyer/${sheet.ulid}/share`,
                                            )
                                        }
                                    >
                                        Share link
                                    </Button>
                                    <Button variant="outline" asChild>
                                        <a
                                            href={`/pricing/buyer/${sheet.ulid}/pdf`}
                                        >
                                            PDF
                                        </a>
                                    </Button>
                                </>
                            )}
                        </div>
                        {shareUrl && (
                            <p className="rounded bg-emerald-50 p-2 text-sm break-all dark:bg-emerald-950/30">
                                {shareUrl}
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
