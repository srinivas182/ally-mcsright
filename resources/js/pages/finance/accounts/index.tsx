import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney, type Money } from '@/lib/money';

type Account = {
    ulid: string;
    code: string;
    name: string;
    type: string;
    status: string;
    is_system: boolean;
    is_subledger: boolean;
    balance: Money;
};

type Props = {
    accounts: Account[];
    types: string[];
    can: { adjust: boolean };
};

export default function AccountsIndex({ accounts, types, can }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ code: '', name: '', type: 'expense' });

    return (
        <>
            <Head title="Chart of accounts" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Chart of accounts
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Balances are shown in each account&apos;s natural
                            sign.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/finance/journals">Journals</Link>
                        </Button>
                        {can.adjust && (
                            <Button onClick={() => setOpen(true)}>
                                Add account
                            </Button>
                        )}
                    </div>
                </header>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Code</th>
                                <th className="p-3 font-medium">Account</th>
                                <th className="p-3 font-medium">Type</th>
                                <th className="p-3 text-right font-medium">
                                    Balance
                                </th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {accounts.map((account) => (
                                <tr
                                    key={account.ulid}
                                    className="border-t hover:bg-muted/30"
                                >
                                    <td className="p-3 font-mono">
                                        {account.code}
                                    </td>
                                    <td
                                        className={
                                            account.is_subledger
                                                ? 'p-3 pl-8'
                                                : 'p-3'
                                        }
                                    >
                                        <Link
                                            href={`/finance/accounts/${account.ulid}/statement`}
                                            className="text-primary hover:underline"
                                        >
                                            {account.name}
                                        </Link>{' '}
                                        {account.status === 'archived' && (
                                            <Badge variant="secondary">
                                                Archived
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="p-3 capitalize">
                                        {account.type}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {formatMoney(account.balance)}
                                    </td>
                                    <td className="p-3 text-right">
                                        {can.adjust &&
                                            !account.is_system &&
                                            account.status === 'active' && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/finance/accounts/${account.ulid}/archive`,
                                                        )
                                                    }
                                                >
                                                    Archive
                                                </Button>
                                            )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add account</DialogTitle>
                    </DialogHeader>
                    <form
                        id="account-form"
                        className="grid gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/finance/accounts', {
                                onSuccess: () => {
                                    setOpen(false);
                                    form.reset();
                                },
                            });
                        }}
                    >
                        <FormField
                            label="Code"
                            help="4 digits, optionally with a suffix, e.g. 6100 or 6100-MKTG"
                            error={form.errors.code}
                            required
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    value={form.data.code}
                                    onChange={(e) =>
                                        form.setData(
                                            'code',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Name"
                            error={form.errors.name}
                            required
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                />
                            )}
                        </FormField>
                        <FormField label="Type" error={form.errors.type}>
                            {(control) => (
                                <Select
                                    value={form.data.type}
                                    onValueChange={(type) =>
                                        form.setData('type', type)
                                    }
                                >
                                    <SelectTrigger {...control}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {types.map((type) => (
                                            <SelectItem
                                                key={type}
                                                value={type}
                                                className="capitalize"
                                            >
                                                {type}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
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
                            form="account-form"
                            disabled={form.processing}
                        >
                            Add account
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

AccountsIndex.layout = {
    breadcrumbs: [{ title: 'Chart of accounts', href: '/finance/accounts' }],
};
