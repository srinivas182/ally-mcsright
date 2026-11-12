import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Props = {
    titles: {
        ulid: string;
        name: string;
        email: string;
        phone: string;
        verified_at: string | null;
        note: string | null;
    }[];
    wire: {
        bank: string | null;
        last4: string | null;
        verified_at: string | null;
        pending: { bank: string; last4: string; by: number; at: string } | null;
    };
    canChangeWire: boolean;
    year: number;
    referralsPaid: {
        brokerage: string;
        agent: string;
        deals: number;
        paid_cents: number;
        w9: boolean;
    }[];
};

function Title({ t }: { t: Props['titles'][number] }) {
    const [note, setNote] = useState('');

    return (
        <li className="grid gap-1 p-3 text-sm">
            <span className="flex items-center gap-2 font-medium">
                {t.name}{' '}
                {t.verified_at ? (
                    <Badge variant="secondary">Verified</Badge>
                ) : (
                    <Badge variant="outline">Not verified</Badge>
                )}
            </span>
            <span className="text-muted-foreground">
                {t.email} · {t.phone}
                {t.note ? ` · ${t.note}` : ''}
            </span>
            {!t.verified_at && (
                <span className="flex flex-wrap gap-2">
                    <Input
                        aria-label="Call-back note"
                        placeholder="Called 555-0100 from their website; spoke to Jo, escrow officer"
                        className="max-w-md"
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={note.length < 15}
                        onClick={() =>
                            router.post(
                                `/settings/transactions/titles/${t.ulid}/verify`,
                                { note },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Mark verified
                    </Button>
                </span>
            )}
        </li>
    );
}

/** Title companies, the brokerage's receiving account, and referral fees paid (1099 data). */
export default function TransactionsSettings({
    titles,
    wire,
    canChangeWire,
    year,
    referralsPaid,
}: Props) {
    const title = useForm({ name: '', email: '', phone: '' });
    const proposal = useForm({ bank: '', last4: '' });
    const [confirmNote, setConfirmNote] = useState('');

    return (
        <>
            <Head title="Transactions" />
            <div className="flex flex-col gap-5">
                <PageHeader
                    title="Transactions"
                    description="Title companies, the brokerage's receiving account, and referral fees paid."
                />
                <section className="grid gap-2">
                    <h2 className="font-semibold">Title companies</h2>
                    <p className="text-sm text-muted-foreground">
                        CDAs are sent only to title companies verified by
                        calling a number you already trust — never one from an
                        email.
                    </p>
                    <ul className="divide-y rounded-lg border">
                        {titles.map((t) => (
                            <Title key={t.ulid} t={t} />
                        ))}
                    </ul>
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            title.post('/settings/transactions/titles', {
                                preserveScroll: true,
                                onSuccess: () => title.reset(),
                            });
                        }}
                    >
                        <Input
                            aria-label="Title company name"
                            placeholder="Name"
                            className="w-48"
                            value={title.data.name}
                            onChange={(e) =>
                                title.setData('name', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Title company email"
                            placeholder="Email"
                            className="w-56"
                            value={title.data.email}
                            onChange={(e) =>
                                title.setData('email', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Title company phone"
                            placeholder="Phone"
                            className="w-40"
                            value={title.data.phone}
                            onChange={(e) =>
                                title.setData('phone', e.target.value)
                            }
                        />
                        <Button type="submit" variant="outline">
                            Add
                        </Button>
                    </form>
                </section>
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">
                        Brokerage receiving account
                    </h2>
                    <p>
                        {wire.last4
                            ? `${wire.bank} account ending ${wire.last4}, verified ${wire.verified_at ? new Date(wire.verified_at).toLocaleDateString() : ''}`
                            : 'Not set.'}{' '}
                        Only the last four digits are kept.
                    </p>
                    {canChangeWire &&
                        (wire.pending ? (
                            <span className="flex flex-wrap gap-2">
                                Change to {wire.pending.bank} ending{' '}
                                {wire.pending.last4} waits for a second person's
                                call-back:
                                <Input
                                    aria-label="Call-back note"
                                    className="max-w-md"
                                    placeholder="Called the bank at the number on our statement; confirmed by…"
                                    value={confirmNote}
                                    onChange={(e) =>
                                        setConfirmNote(e.target.value)
                                    }
                                />
                                <Button
                                    size="sm"
                                    disabled={confirmNote.length < 15}
                                    onClick={() =>
                                        router.post(
                                            '/settings/transactions/wire/confirm',
                                            { note: confirmNote },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Confirm change
                                </Button>
                            </span>
                        ) : (
                            <form
                                className="flex flex-wrap gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    proposal.post(
                                        '/settings/transactions/wire/propose',
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <Input
                                    aria-label="Bank name"
                                    placeholder="Bank"
                                    className="w-48"
                                    value={proposal.data.bank}
                                    onChange={(e) =>
                                        proposal.setData('bank', e.target.value)
                                    }
                                />
                                <Input
                                    aria-label="Last four digits"
                                    placeholder="Last 4"
                                    maxLength={4}
                                    className="w-24"
                                    value={proposal.data.last4}
                                    onChange={(e) =>
                                        proposal.setData(
                                            'last4',
                                            e.target.value,
                                        )
                                    }
                                />
                                <Button type="submit" variant="outline">
                                    Propose change
                                </Button>
                            </form>
                        ))}
                    {Object.values(proposal.errors)[0] && (
                        <p role="alert" className="text-destructive">
                            {Object.values(proposal.errors)[0]}
                        </p>
                    )}
                </section>
                <section className="grid gap-2">
                    <h2 className="font-semibold">
                        Referral fees paid in {year} (for 1099-NEC)
                    </h2>
                    <ul className="divide-y rounded-lg border text-sm">
                        {referralsPaid.length === 0 && (
                            <li className="p-3 text-muted-foreground">None.</li>
                        )}
                        {referralsPaid.map((r) => (
                            <li
                                key={r.brokerage + r.agent}
                                className="flex flex-wrap justify-between gap-2 p-3"
                            >
                                <span>
                                    {r.agent}, {r.brokerage} · {r.deals} deal(s)
                                </span>
                                <span>
                                    {usd(r.paid_cents)}
                                    {r.w9 ? '' : ' · W-9 missing'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

TransactionsSettings.layout = {
    breadcrumbs: [{ title: 'Transactions', href: '/settings/transactions' }],
};
