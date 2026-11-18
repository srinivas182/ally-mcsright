import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usd } from '@/lib/cents';

import type { NetworkReferral } from './types';

/** One referral, received or sent, with the next step for whoever's turn it is. */
export function ReferralItem({
    r,
    received,
    deals,
}: {
    r: NetworkReferral;
    received: boolean;
    deals: { ulid: string; label: string }[];
}) {
    const accept = useForm({ agree: false });
    const update = useForm({
        status: r.status === 'accepted' ? 'under_contract' : 'closed',
        deal: r.deal ?? '',
        fee_due: '',
        closed_on: '',
    });
    const [declining, setDeclining] = useState(false);
    const [reason, setReason] = useState('');
    const base = `/network/referrals/${r.ulid}`;
    const who = r.other ? `${r.other.name} (${r.other.brokerage})` : '';

    return (
        <li className="flex flex-col gap-2 rounded-lg border p-4 text-base">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <strong>
                    {r.side === 'buyer' ? 'Buyer' : 'Seller'} in {r.area}
                </strong>
                <Badge
                    variant={
                        ['declined', 'expired', 'fell_through'].includes(
                            r.status,
                        )
                            ? 'secondary'
                            : 'default'
                    }
                >
                    {r.status_label}
                </Badge>
            </div>
            <p className="text-sm text-muted-foreground">
                {received ? 'From' : 'To'} {who} · sent {r.sent_at} · fee{' '}
                {r.fee_percent}%
            </p>
            {(r.price_band || r.timing || r.language) && (
                <p>
                    {[
                        r.price_band,
                        r.timing,
                        r.language && `speaks ${r.language}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            )}
            {r.needs && <p>{r.needs}</p>}
            {r.client && (
                <p className="rounded-md bg-muted/40 p-2">
                    Client:{' '}
                    <strong>
                        {r.client.first_name} {r.client.last_name}
                    </strong>
                    {r.client.phone && (
                        <>
                            {' · '}
                            <a
                                className="underline"
                                href={`tel:${r.client.phone}`}
                            >
                                {r.client.phone}
                            </a>
                        </>
                    )}
                    {r.client.email && (
                        <>
                            {' · '}
                            <a
                                className="underline"
                                href={`mailto:${r.client.email}`}
                            >
                                {r.client.email}
                            </a>
                        </>
                    )}
                </p>
            )}
            {r.agreement && (
                <p className="text-sm text-muted-foreground">{r.agreement}</p>
            )}
            {r.reason && <p className="text-sm">Reason: {r.reason}</p>}
            {r.fee_due_cents !== null && (
                <p>
                    Referral fee owed: <strong>{usd(r.fee_due_cents)}</strong>
                    {r.fee_paid
                        ? ' · received'
                        : r.fee_sent
                          ? ' · sent, waiting for confirmation'
                          : ' · not sent yet'}
                </p>
            )}

            {received && r.status === 'sent' && (
                <div className="flex flex-col gap-2">
                    <p className="text-sm">
                        Please answer by{' '}
                        {r.answer_by
                            ? new Date(r.answer_by).toLocaleString()
                            : 'soon'}
                        . You'll see the client's details once you accept.
                    </p>
                    <label className="flex items-start gap-2">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={accept.data.agree}
                            onChange={(e) =>
                                accept.setData('agree', e.target.checked)
                            }
                        />
                        I agree to pay {r.fee_percent}% of the commission I earn
                        on this client to {r.other?.brokerage}, brokerage to
                        brokerage, at closing.
                    </label>
                    <InputError message={accept.errors.agree} />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            size="lg"
                            disabled={accept.processing}
                            onClick={() =>
                                accept.post(`${base}/accept`, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Accept
                        </Button>
                        <Button
                            size="lg"
                            variant="outline"
                            onClick={() => setDeclining(true)}
                        >
                            I can't take it
                        </Button>
                    </div>
                    {declining && (
                        <div className="flex flex-wrap items-end gap-2">
                            <label className="grid flex-1 gap-1">
                                Reason (optional, shown to {r.other?.name})
                                <Input
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                            </label>
                            <Button
                                variant="destructive"
                                onClick={() =>
                                    router.post(
                                        `${base}/decline`,
                                        { reason },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Decline
                            </Button>
                        </div>
                    )}
                </div>
            )}

            {received && ['accepted', 'under_contract'].includes(r.status) && (
                <form
                    className="grid gap-2 rounded-md border p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        update.post(`${base}/update`, { preserveScroll: true });
                    }}
                >
                    <label className="grid gap-1">
                        Update the referring agent
                        <select
                            className="min-h-11 rounded-md border bg-transparent px-2"
                            value={update.data.status}
                            onChange={(e) =>
                                update.setData('status', e.target.value)
                            }
                        >
                            {r.status === 'accepted' && (
                                <option value="under_contract">
                                    Under contract
                                </option>
                            )}
                            {r.status === 'under_contract' && (
                                <option value="closed">Closed</option>
                            )}
                            <option value="fell_through">
                                Didn't work out
                            </option>
                        </select>
                    </label>
                    {update.data.status === 'under_contract' &&
                        deals.length > 0 && (
                            <label className="grid gap-1">
                                Which of your deals is it? (adds the referral
                                fee to the commission)
                                <select
                                    className="min-h-11 rounded-md border bg-transparent px-2"
                                    value={update.data.deal}
                                    onChange={(e) =>
                                        update.setData('deal', e.target.value)
                                    }
                                >
                                    <option value="">
                                        Not in RightAlly yet
                                    </option>
                                    {deals.map((d) => (
                                        <option key={d.ulid} value={d.ulid}>
                                            {d.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    <InputError message={update.errors.deal} />
                    {update.data.status === 'closed' && (
                        <div className="grid gap-2 sm:grid-cols-2">
                            <label className="grid gap-1">
                                Referral fee owed ($)
                                <Input
                                    inputMode="decimal"
                                    value={update.data.fee_due}
                                    onChange={(e) =>
                                        update.setData(
                                            'fee_due',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1">
                                Closing date
                                <Input
                                    type="date"
                                    value={update.data.closed_on}
                                    onChange={(e) =>
                                        update.setData(
                                            'closed_on',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                        </div>
                    )}
                    <InputError message={update.errors.fee_due} />
                    <InputError message={update.errors.status} />
                    <div>
                        <Button type="submit" disabled={update.processing}>
                            Send update
                        </Button>
                    </div>
                </form>
            )}

            {r.status === 'closed' && received && !r.fee_sent && (
                <div>
                    <Button
                        onClick={() =>
                            router.post(
                                `${base}/fee`,
                                { step: 'sent' },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Our brokerage sent the fee
                    </Button>
                </div>
            )}
            {r.status === 'closed' && !received && !r.fee_paid && (
                <div>
                    <Button
                        onClick={() =>
                            router.post(
                                `${base}/fee`,
                                { step: 'paid' },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Our brokerage received the fee
                    </Button>
                </div>
            )}
        </li>
    );
}
