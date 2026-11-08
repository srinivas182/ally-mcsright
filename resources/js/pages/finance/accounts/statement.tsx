import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney, type Money } from '@/lib/money';

type Line = {
    date: string;
    journal: string;
    type: string;
    description: string | null;
    memo: string | null;
    amount: Money;
    balance: Money;
};

type Props = {
    account: { ulid: string; code: string; name: string; type: string };
    from: string;
    to: string;
    opening: Money;
    closing: Money;
    lines: Line[];
    can: { export: boolean };
};

export default function Statement(props: Props) {
    const { account, opening, closing, lines, can } = props;
    const [from, setFrom] = useState(props.from);
    const [to, setTo] = useState(props.to);
    const base = `/finance/accounts/${account.ulid}`;

    return (
        <>
            <Head title={`Statement ${account.code}`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">
                        {account.code} {account.name}
                    </h1>
                    <p className="text-sm text-muted-foreground capitalize">
                        {account.type} account statement
                    </p>
                </header>

                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(`${base}/statement`, { from, to });
                    }}
                >
                    <div className="grid gap-1">
                        <Label htmlFor="from">From</Label>
                        <Input
                            id="from"
                            type="date"
                            value={from}
                            onChange={(e) => setFrom(e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="to">To</Label>
                        <Input
                            id="to"
                            type="date"
                            value={to}
                            onChange={(e) => setTo(e.target.value)}
                        />
                    </div>
                    <Button type="submit" variant="secondary">
                        Show
                    </Button>
                    {can.export && (
                        <Button variant="outline" asChild>
                            <a
                                href={`${base}/statement.csv?from=${props.from}&to=${props.to}`}
                            >
                                Download CSV
                            </a>
                        </Button>
                    )}
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Date</th>
                                <th className="p-3 font-medium">Description</th>
                                <th className="p-3 text-right font-medium">
                                    Amount
                                </th>
                                <th className="p-3 text-right font-medium">
                                    Balance
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr className="border-t bg-muted/20">
                                <td className="p-3">{props.from}</td>
                                <td className="p-3 font-medium">
                                    Opening balance
                                </td>
                                <td className="p-3" />
                                <td className="p-3 text-right tabular-nums">
                                    {formatMoney(opening)}
                                </td>
                            </tr>
                            {lines.length === 0 && (
                                <tr className="border-t">
                                    <td
                                        colSpan={4}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        No entries in this period.
                                    </td>
                                </tr>
                            )}
                            {lines.map((line, index) => (
                                <tr key={index} className="border-t">
                                    <td className="p-3">{line.date}</td>
                                    <td className="p-3">
                                        <Link
                                            href={`/finance/journals/${line.journal}`}
                                            className="text-primary hover:underline"
                                        >
                                            {line.description ?? line.type}
                                        </Link>
                                        {line.memo && (
                                            <span className="block text-xs text-muted-foreground">
                                                {line.memo}
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {formatMoney(line.amount)}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {formatMoney(line.balance)}
                                    </td>
                                </tr>
                            ))}
                            <tr className="border-t bg-muted/20">
                                <td className="p-3">{props.to}</td>
                                <td className="p-3 font-medium">
                                    Closing balance
                                </td>
                                <td className="p-3" />
                                <td className="p-3 text-right font-medium tabular-nums">
                                    {formatMoney(closing)}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

Statement.layout = {
    breadcrumbs: [{ title: 'Chart of accounts', href: '/finance/accounts' }],
};
