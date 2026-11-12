import { Head, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Version = {
    version: number;
    effective_from: string;
    stage: string;
    calc: string;
    amount: string;
    charged_to: string;
    payable_to: string;
    counts_toward_cap: number | boolean;
    revenue_share_eligible: number | boolean;
    annual_cap_cents: number | null;
};
type Props = {
    fees: { code: string; name: string; versions: Version[] }[];
    canManage: boolean;
};

const describe = (v: Version) =>
    [
        v.calc === 'flat' ? usd(Number(v.amount)) : `${v.amount}%`,
        v.stage === 'pre' ? 'before the split' : 'after the split',
        v.charged_to === 'brokerage'
            ? 'paid by the brokerage'
            : 'paid by the agent',
        `to the ${v.payable_to}`,
        v.counts_toward_cap ? 'counts toward cap' : null,
        v.annual_cap_cents
            ? `capped at ${usd(v.annual_cap_cents)} a year`
            : null,
    ]
        .filter(Boolean)
        .join(' · ');

/** The fee catalogue: each change is a new version from a date. */
export default function Fees({ fees, canManage }: Props) {
    const form = useForm({
        code: '',
        name: '',
        effective_from: new Date().toISOString().slice(0, 10),
        stage: 'post',
        calc: 'flat',
        amount: '',
        charged_to: 'agent',
        payable_to: 'brokerage',
        counts_toward_cap: false,
        revenue_share_eligible: true,
        annual_cap: '',
    });

    return (
        <>
            <Head title="Fees" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Fee catalogue"
                    description="Plans pick fees from here. A change is a new version from a date; deals use the version in force when they close."
                />
                <ul className="grid gap-3 md:grid-cols-2">
                    {fees.map((f) => (
                        <li
                            key={f.code}
                            className="grid gap-1 rounded-lg border p-3 text-sm"
                        >
                            <span className="font-medium">
                                {f.name}{' '}
                                <Badge variant="outline">{f.code}</Badge>
                            </span>
                            {f.versions.map((v) => (
                                <span
                                    key={v.version}
                                    className="text-muted-foreground"
                                >
                                    v{v.version} from{' '}
                                    {new Date(
                                        v.effective_from,
                                    ).toLocaleDateString()}
                                    : {describe(v)}
                                </span>
                            ))}
                        </li>
                    ))}
                </ul>
                {canManage && (
                    <form
                        className="grid gap-2 rounded-lg border p-4 text-sm md:grid-cols-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/compensation/fees', {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <Input
                            aria-label="Code"
                            placeholder="tech_fee"
                            value={form.data.code}
                            onChange={(e) =>
                                form.setData('code', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Name"
                            placeholder="Technology fee"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Effective from"
                            type="date"
                            value={form.data.effective_from}
                            onChange={(e) =>
                                form.setData('effective_from', e.target.value)
                            }
                        />
                        <select
                            aria-label="When"
                            className="rounded border bg-background px-2 py-1.5"
                            value={form.data.stage}
                            onChange={(e) =>
                                form.setData('stage', e.target.value)
                            }
                        >
                            <option value="post">After the split</option>
                            <option value="pre">Before the split</option>
                        </select>
                        <span className="flex gap-2">
                            <select
                                aria-label="Type"
                                className="rounded border bg-background px-2 py-1.5"
                                value={form.data.calc}
                                onChange={(e) =>
                                    form.setData('calc', e.target.value)
                                }
                            >
                                <option value="flat">Flat $</option>
                                <option value="percent">Percent %</option>
                            </select>
                            <Input
                                aria-label="Amount"
                                type="number"
                                min={0}
                                step="0.01"
                                value={form.data.amount}
                                onChange={(e) =>
                                    form.setData('amount', e.target.value)
                                }
                            />
                        </span>
                        <select
                            aria-label="Paid by"
                            className="rounded border bg-background px-2 py-1.5"
                            value={form.data.charged_to}
                            onChange={(e) =>
                                form.setData('charged_to', e.target.value)
                            }
                        >
                            <option value="agent">Paid by the agent</option>
                            <option value="brokerage">
                                Paid by the brokerage
                            </option>
                        </select>
                        <select
                            aria-label="Paid to"
                            className="rounded border bg-background px-2 py-1.5"
                            value={form.data.payable_to}
                            onChange={(e) =>
                                form.setData('payable_to', e.target.value)
                            }
                        >
                            <option value="brokerage">
                                Paid to the brokerage
                            </option>
                            <option value="franchisor">
                                Paid to the franchisor
                            </option>
                            <option value="vendor">Paid to a vendor</option>
                        </select>
                        <Input
                            aria-label="Yearly cap ($, optional)"
                            placeholder="Yearly cap $ (optional)"
                            type="number"
                            min={0}
                            value={form.data.annual_cap}
                            onChange={(e) =>
                                form.setData('annual_cap', e.target.value)
                            }
                        />
                        <span className="flex flex-wrap gap-3">
                            <label className="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={form.data.counts_toward_cap}
                                    onChange={(e) =>
                                        form.setData(
                                            'counts_toward_cap',
                                            e.target.checked,
                                        )
                                    }
                                />{' '}
                                Counts toward cap
                            </label>
                            <label className="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={form.data.revenue_share_eligible}
                                    onChange={(e) =>
                                        form.setData(
                                            'revenue_share_eligible',
                                            e.target.checked,
                                        )
                                    }
                                />{' '}
                                Revenue-share eligible
                            </label>
                        </span>
                        {Object.values(form.errors).length > 0 && (
                            <p
                                role="alert"
                                className="text-destructive md:col-span-3"
                            >
                                {Object.values(form.errors)[0]}
                            </p>
                        )}
                        <Button
                            type="submit"
                            className="justify-self-start"
                            disabled={form.processing}
                        >
                            Publish fee version
                        </Button>
                    </form>
                )}
            </div>
        </>
    );
}

Fees.layout = { breadcrumbs: [{ title: 'Fees', href: '/compensation/fees' }] };
