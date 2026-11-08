import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FormField } from '@/components/ui/form-field';
import { Textarea } from '@/components/ui/textarea';
import { formatMoney, type Money } from '@/lib/money';

type Props = {
    journal: {
        ulid: string;
        type: string;
        date: string;
        posted_at: string;
        description: string | null;
        source: string | null;
        reversal_of: string | null;
        reversed_by: string | null;
        is_manual: boolean;
    };
    lines: {
        account: string;
        account_ulid: string | null;
        debit: Money;
        credit: Money;
        memo: string | null;
    }[];
    can: { reverse: boolean };
};

export default function ShowJournal({ journal, lines, can }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });
    const totalDebit = lines.reduce((sum, l) => sum + l.debit.cents, 0);
    const totalCredit = lines.reduce((sum, l) => sum + l.credit.cents, 0);

    return (
        <>
            <Head title="Journal" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="grid gap-1">
                        <h1 className="text-2xl font-semibold">
                            {journal.description ?? 'Journal'}
                        </h1>
                        <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <span>{journal.date}</span>
                            <span className="font-mono text-xs">
                                {journal.type}
                            </span>
                            {journal.is_manual && (
                                <Badge variant="outline">Manual</Badge>
                            )}
                            {journal.source && <span>{journal.source}</span>}
                        </p>
                        {journal.reversal_of && (
                            <p className="text-sm">
                                Reverses{' '}
                                <Link
                                    className="text-primary hover:underline"
                                    href={`/finance/journals/${journal.reversal_of}`}
                                >
                                    the original journal
                                </Link>
                            </p>
                        )}
                        {journal.reversed_by && (
                            <p className="text-sm">
                                Reversed by{' '}
                                <Link
                                    className="text-primary hover:underline"
                                    href={`/finance/journals/${journal.reversed_by}`}
                                >
                                    this reversal
                                </Link>
                            </p>
                        )}
                    </div>
                    {can.reverse && (
                        <Button
                            variant="destructive"
                            onClick={() => setOpen(true)}
                        >
                            Reverse
                        </Button>
                    )}
                </header>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Account</th>
                                <th className="p-3 font-medium">Memo</th>
                                <th className="p-3 text-right font-medium">
                                    Debit
                                </th>
                                <th className="p-3 text-right font-medium">
                                    Credit
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((line, index) => (
                                <tr key={index} className="border-t">
                                    <td className="p-3">{line.account}</td>
                                    <td className="p-3 text-muted-foreground">
                                        {line.memo}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {line.debit.cents > 0
                                            ? formatMoney(line.debit)
                                            : ''}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {line.credit.cents > 0
                                            ? formatMoney(line.credit)
                                            : ''}
                                    </td>
                                </tr>
                            ))}
                            <tr className="border-t bg-muted/20 font-medium">
                                <td className="p-3" colSpan={2}>
                                    Totals
                                </td>
                                <td className="p-3 text-right tabular-nums">
                                    {formatMoney({
                                        cents: totalDebit,
                                        currency: 'USD',
                                    })}
                                </td>
                                <td className="p-3 text-right tabular-nums">
                                    {formatMoney({
                                        cents: totalCredit,
                                        currency: 'USD',
                                    })}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Reverse journal</DialogTitle>
                        <DialogDescription>
                            Posts a new journal with every line swapped, dated
                            today. The original stays in history.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        id="reverse-form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(
                                `/finance/journals/${journal.ulid}/reverse`,
                            );
                        }}
                    >
                        <FormField
                            label="Reason"
                            error={form.errors.reason}
                            required
                        >
                            {(control) => (
                                <Textarea
                                    {...control}
                                    value={form.data.reason}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                />
                            )}
                        </FormField>
                    </form>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="reverse-form"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Reverse
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

ShowJournal.layout = {
    breadcrumbs: [{ title: 'Journals', href: '/finance/journals' }],
};
