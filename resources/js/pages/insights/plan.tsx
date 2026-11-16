import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Counts = {
    conversations: number;
    appointments: number;
    agreements: number;
    closings: number;
};
type Props = {
    year: number;
    inputs: { income_cents: number; take_home: boolean };
    rates: {
        average_price_cents: number;
        commission_rate: number;
        net_share: number;
        closing_rate: number;
        agreement_rate: number;
        appointment_rate: number;
        from_history: string[];
    };
    plan: {
        gci_cents: number;
        closings: number;
        agreements: number;
        appointments: number;
        conversations: number;
        weekly: {
            closings: number;
            agreements: number;
            appointments: number;
            conversations: number;
        };
    } | null;
    week: Counts;
    ytd: Counts;
    year_share: number;
};

const rows: [keyof Counts, string][] = [
    ['conversations', 'Conversations'],
    ['appointments', 'Appointments'],
    ['agreements', 'Agreements (deals started)'],
    ['closings', 'Closings'],
];

/** The business planner (specs/insights/01, K3): from an income goal back to this week. */
export default function Plan(p: Props) {
    const [form, setForm] = useState({
        income: p.inputs.income_cents
            ? String(p.inputs.income_cents / 100)
            : '',
        take_home: p.inputs.take_home,
        average_price: String(Math.round(p.rates.average_price_cents / 100)),
        commission_rate: String(
            Math.round(p.rates.commission_rate * 10000) / 100,
        ),
        closing_rate: String(Math.round(p.rates.closing_rate * 100)),
        agreement_rate: String(Math.round(p.rates.agreement_rate * 100)),
        appointment_rate: String(Math.round(p.rates.appointment_rate * 100)),
    });
    const set = (k: keyof typeof form, v: string | boolean) =>
        setForm({ ...form, [k]: v });
    const field = (k: keyof typeof form, label: string, suffix = '') => (
        <label className="grid gap-1">
            <span className="text-muted-foreground">{label}</span>
            <span className="flex items-center gap-1">
                <Input
                    type="number"
                    value={String(form[k])}
                    onChange={(e) => set(k, e.target.value)}
                />
                {suffix}
            </span>
        </label>
    );

    return (
        <>
            <Head title="Business planner" />
            <div className="flex max-w-4xl flex-col gap-4 p-4 text-sm md:p-6">
                <PageHeader
                    title={`Business planner ${p.year}`}
                    description="Start from what you want to earn. We work back to the conversations you need each week, using your own numbers where you have them."
                />
                <section className="grid gap-3 rounded-lg border p-4 md:grid-cols-2">
                    {field('income', 'What I want to earn this year ($)')}
                    <label className="flex items-center gap-2 self-end">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={form.take_home}
                            onChange={(e) => set('take_home', e.target.checked)}
                        />
                        That's take-home, after my split and fees
                    </label>
                    {field('average_price', 'Average sale price ($)')}
                    {field('commission_rate', 'My commission', '%')}
                    {field('closing_rate', 'Deals started that close', '%')}
                    {field('agreement_rate', 'Appointments that sign', '%')}
                    {field(
                        'appointment_rate',
                        'Conversations that book an appointment',
                        '%',
                    )}
                    <p className="text-muted-foreground md:col-span-2">
                        {p.rates.from_history.length > 0
                            ? `From your last 12 months: ${p.rates.from_history.join(', ')}. Change any number to plan differently.`
                            : 'You have little history yet, so these are common starting points. Change any of them.'}
                    </p>
                    <Button
                        className="justify-self-start"
                        disabled={form.income === ''}
                        onClick={() =>
                            router.post(
                                '/insights/plan',
                                {
                                    income: form.income,
                                    take_home: form.take_home,
                                    average_price: form.average_price,
                                    commission_rate: form.commission_rate,
                                    closing_rate: form.closing_rate,
                                    agreement_rate: form.agreement_rate,
                                    appointment_rate: form.appointment_rate,
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Make my plan
                    </Button>
                </section>
                {p.plan && (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <p className="font-medium">
                            To earn {usd(p.inputs.income_cents)}
                            {p.inputs.take_home ? ' take-home' : ''}, you need
                            about {usd(p.plan.gci_cents)} in GCI.
                        </p>
                        <table className="w-full text-left">
                            <thead className="text-muted-foreground">
                                <tr>
                                    <th className="py-1 font-normal">
                                        Activity
                                    </th>
                                    <th className="py-1 text-right font-normal">
                                        This year's target
                                    </th>
                                    <th className="py-1 text-right font-normal">
                                        Done so far
                                    </th>
                                    <th className="py-1 text-right font-normal">
                                        Each week
                                    </th>
                                    <th className="py-1 text-right font-normal">
                                        This week
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map(([k, label]) => {
                                    const target = p.plan![k];
                                    const pace = Math.round(
                                        target * p.year_share,
                                    );
                                    const weekly = p.plan!.weekly[k];
                                    return (
                                        <tr key={k} className="border-t">
                                            <td className="py-1">{label}</td>
                                            <td className="text-right">
                                                {target}
                                            </td>
                                            <td
                                                className={`text-right ${p.ytd[k] < pace ? 'text-destructive' : ''}`}
                                            >
                                                {p.ytd[k]} (pace {pace})
                                            </td>
                                            <td className="text-right">
                                                {weekly}
                                            </td>
                                            <td
                                                className={`text-right ${p.week[k] < weekly ? 'font-medium' : ''}`}
                                            >
                                                {p.week[k]}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                        <p className="text-muted-foreground">
                            Conversations count connected calls, meetings and
                            the texts and emails you send. Appointments count
                            meetings and completed showings.
                        </p>
                    </section>
                )}
            </div>
        </>
    );
}

Plan.layout = {
    breadcrumbs: [{ title: 'Business planner', href: '/insights/plan' }],
};
