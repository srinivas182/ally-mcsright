import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney } from '@/lib/money';

type Line = { account: string; side: string; amount: string; memo: string };

type Props = { accounts: { ulid: string; label: string }[]; today: string };

/** Parse "1,250.00" into integer cents without floating-point arithmetic. */
function toCents(value: string): number {
    const match = value
        .replace(/,/g, '')
        .trim()
        .match(/^(\d+)(?:\.(\d{1,2}))?$/);
    if (!match) {
        return 0;
    }
    return Number(match[1]) * 100 + Number((match[2] ?? '0').padEnd(2, '0'));
}

const blank = (side: string): Line => ({
    account: '',
    side,
    amount: '',
    memo: '',
});

export default function CreateAdjustment({ accounts, today }: Props) {
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const form = useForm({
        description: '',
        effective_date: today,
        idempotency_key: idempotencyKey,
        lines: [blank('debit'), blank('credit')],
    });

    const debits = form.data.lines
        .filter((l) => l.side === 'debit')
        .reduce((sum, l) => sum + toCents(l.amount), 0);
    const credits = form.data.lines
        .filter((l) => l.side === 'credit')
        .reduce((sum, l) => sum + toCents(l.amount), 0);
    const balanced = debits === credits && debits > 0;
    const errors = form.errors as Record<string, string | undefined>;

    function update(index: number, patch: Partial<Line>) {
        form.setData(
            'lines',
            form.data.lines.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );
    }

    return (
        <>
            <Head title="Manual adjustment" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">
                        Manual adjustment
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Flagged as manual in every report. Debits must equal
                        credits.
                    </p>
                </header>

                <form
                    className="grid gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/finance/adjustments');
                    }}
                >
                    <div className="grid gap-5 md:grid-cols-3">
                        <FormField
                            label="Description"
                            error={form.errors.description}
                            required
                            className="md:col-span-2"
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Date"
                            error={form.errors.effective_date}
                            required
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    type="date"
                                    value={form.data.effective_date}
                                    onChange={(e) =>
                                        form.setData(
                                            'effective_date',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                    </div>

                    <div className="grid gap-3">
                        {form.data.lines.map((line, index) => (
                            <div
                                key={index}
                                className="grid items-start gap-3 rounded-lg border p-3 md:grid-cols-[2fr_1fr_1fr_2fr_auto]"
                            >
                                <Select
                                    value={line.account}
                                    onValueChange={(account) =>
                                        update(index, { account })
                                    }
                                >
                                    <SelectTrigger
                                        aria-label={`Line ${index + 1} account`}
                                        aria-invalid={
                                            errors[`lines.${index}.account`]
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <SelectValue placeholder="Account" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {accounts.map((account) => (
                                            <SelectItem
                                                key={account.ulid}
                                                value={account.ulid}
                                            >
                                                {account.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Select
                                    value={line.side}
                                    onValueChange={(side) =>
                                        update(index, { side })
                                    }
                                >
                                    <SelectTrigger
                                        aria-label={`Line ${index + 1} side`}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="debit">
                                            Debit
                                        </SelectItem>
                                        <SelectItem value="credit">
                                            Credit
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Input
                                    aria-label={`Line ${index + 1} amount`}
                                    inputMode="decimal"
                                    placeholder="0.00"
                                    value={line.amount}
                                    aria-invalid={
                                        errors[`lines.${index}.amount`]
                                            ? true
                                            : undefined
                                    }
                                    onChange={(e) =>
                                        update(index, {
                                            amount: e.target.value,
                                        })
                                    }
                                />
                                <Input
                                    aria-label={`Line ${index + 1} memo`}
                                    placeholder="Memo"
                                    value={line.memo}
                                    onChange={(e) =>
                                        update(index, { memo: e.target.value })
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    disabled={form.data.lines.length <= 2}
                                    onClick={() =>
                                        form.setData(
                                            'lines',
                                            form.data.lines.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            className="justify-self-start"
                            onClick={() =>
                                form.setData('lines', [
                                    ...form.data.lines,
                                    blank('debit'),
                                ])
                            }
                        >
                            Add line
                        </Button>
                    </div>

                    {errors.lines && (
                        <p role="alert" className="text-sm text-destructive">
                            {errors.lines}
                        </p>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg bg-muted/40 p-4 text-sm">
                        <span>
                            Debits{' '}
                            <strong className="tabular-nums">
                                {formatMoney({
                                    cents: debits,
                                    currency: 'USD',
                                })}
                            </strong>{' '}
                            · Credits{' '}
                            <strong className="tabular-nums">
                                {formatMoney({
                                    cents: credits,
                                    currency: 'USD',
                                })}
                            </strong>
                        </span>
                        <span
                            className={
                                balanced
                                    ? 'text-muted-foreground'
                                    : 'text-destructive'
                            }
                        >
                            {balanced ? 'Balanced' : 'Not balanced'}
                        </span>
                        <Button
                            type="submit"
                            disabled={!balanced || form.processing}
                        >
                            Post adjustment
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

CreateAdjustment.layout = {
    breadcrumbs: [
        { title: 'Journals', href: '/finance/journals' },
        { title: 'Manual adjustment', href: '/finance/adjustments/create' },
    ],
};
