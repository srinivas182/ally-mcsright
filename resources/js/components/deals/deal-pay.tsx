import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type PayOverrides = {
    agent_split_percent?: string;
    waive_fees?: boolean;
    no_cap?: boolean;
    no_revenue_share?: boolean;
    reason?: string;
} | null;

const STATUS: Record<
    string,
    [string, 'default' | 'secondary' | 'destructive' | 'outline']
> = {
    paid: ['Paid in RightAlly', 'default'],
    reversed: ['Reversed', 'secondary'],
    needs_gci: ['Needs the GCI', 'destructive'],
    needs_review: ['Needs a fix', 'destructive'],
};

/**
 * Close there, paid here (specs/connect/12, C5a-3): a closed deal's money for the owner or a compensation manager —
 * where it stands, the broker's overrides (agent split, fees, cap, revenue share), reverse, pay again, or enter a
 * missing GCI. Everything happens in RightAlly only and is audited.
 */
export function DealPay({
    ulid,
    status,
    note,
    overrides,
    importedFrom,
    paidOutside,
}: {
    ulid: string;
    status: string | null;
    note: string | null;
    overrides: PayOverrides;
    importedFrom: string | null;
    paidOutside: boolean;
}) {
    const [open, setOpen] = useState<'override' | 'reverse' | null>(null);
    const override = useForm({
        agent_split_percent: overrides?.agent_split_percent ?? '',
        waive_fees: overrides?.waive_fees ?? false,
        no_cap: overrides?.no_cap ?? false,
        no_revenue_share: overrides?.no_revenue_share ?? false,
        reason: '',
    });
    const reverse = useForm({ reason: '' });
    const gci = useForm({ gci: '' });
    const again = useForm({});
    const label = status ? STATUS[status] : null;

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-medium">Commission</h2>
                {label && <Badge variant={label[1]}>{label[0]}</Badge>}
                {paidOutside && (
                    <Badge variant="outline">
                        Agent's own commission paid outside RightAlly
                    </Badge>
                )}
            </div>
            {note && <p className="text-muted-foreground">{note}</p>}
            {overrides && (
                <p>
                    Your overrides:{' '}
                    {[
                        overrides.agent_split_percent !== undefined &&
                            `agent keeps ${overrides.agent_split_percent}%`,
                        overrides.waive_fees && 'fees waived',
                        overrides.no_cap && 'not counted toward the cap',
                        overrides.no_revenue_share && 'no revenue share',
                    ]
                        .filter(Boolean)
                        .join(', ')}
                    {overrides.reason ? ` (${overrides.reason})` : ''}
                </p>
            )}

            {status === 'needs_gci' && (
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        gci.post(`/deals/${ulid}/pay/gci`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="grid gap-1">
                        Commission (GCI) in dollars
                        <Input
                            inputMode="decimal"
                            value={gci.data.gci}
                            onChange={(e) => gci.setData('gci', e.target.value)}
                        />
                    </label>
                    <Button disabled={gci.processing}>Save and pay</Button>
                    {gci.errors.gci && (
                        <p className="w-full text-destructive">
                            {gci.errors.gci}
                        </p>
                    )}
                </form>
            )}

            <div className="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    onClick={() =>
                        setOpen(open === 'override' ? null : 'override')
                    }
                >
                    Override split, fees or revenue share
                </Button>
                {status === 'paid' || status === null ? (
                    <Button
                        variant="outline"
                        onClick={() =>
                            setOpen(open === 'reverse' ? null : 'reverse')
                        }
                    >
                        Reverse commission
                    </Button>
                ) : (
                    status !== 'needs_gci' && (
                        <Button
                            variant="outline"
                            disabled={again.processing}
                            onClick={() =>
                                again.post(`/deals/${ulid}/pay/again`, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Pay again
                        </Button>
                    )
                )}
            </div>

            {open === 'override' && (
                <form
                    className="grid gap-3 rounded-md border p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        override.post(`/deals/${ulid}/pay/override`, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(null),
                        });
                    }}
                >
                    <label className="grid gap-1">
                        Agent keeps this % of the split (leave empty for their
                        plan)
                        <Input
                            inputMode="decimal"
                            value={override.data.agent_split_percent}
                            onChange={(e) =>
                                override.setData(
                                    'agent_split_percent',
                                    e.target.value,
                                )
                            }
                        />
                    </label>
                    {(
                        [
                            ['waive_fees', "Waive this deal's fees"],
                            ['no_cap', "Don't count this deal toward the cap"],
                            [
                                'no_revenue_share',
                                'No revenue share on this deal',
                            ],
                        ] as const
                    ).map(([key, text]) => (
                        <label key={key} className="flex items-center gap-3">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={override.data[key]}
                                onChange={(e) =>
                                    override.setData(key, e.target.checked)
                                }
                            />
                            {text}
                        </label>
                    ))}
                    <label className="grid gap-1">
                        Why (kept in the audit log)
                        <Input
                            value={override.data.reason}
                            onChange={(e) =>
                                override.setData('reason', e.target.value)
                            }
                        />
                    </label>
                    {Object.values(override.errors).map((m) => (
                        <p key={m} className="text-destructive">
                            {m}
                        </p>
                    ))}
                    <div>
                        <Button disabled={override.processing}>
                            Save and pay again
                        </Button>
                    </div>
                </form>
            )}

            {open === 'reverse' && (
                <form
                    className="grid gap-3 rounded-md border p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        reverse.post(`/deals/${ulid}/pay/reverse`, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(null),
                        });
                    }}
                >
                    <p>
                        This undoes the split, fees, cap progress and revenue
                        share in RightAlly only.
                        {importedFrom
                            ? ` To change the deal itself, change it in ${importedFrom}: RightAlly reads it and pays again.`
                            : ''}
                    </p>
                    <label className="grid gap-1">
                        Why (kept in the audit log)
                        <Input
                            value={reverse.data.reason}
                            onChange={(e) =>
                                reverse.setData('reason', e.target.value)
                            }
                        />
                    </label>
                    {reverse.errors.reason && (
                        <p className="text-destructive">
                            {reverse.errors.reason}
                        </p>
                    )}
                    <div>
                        <Button
                            variant="destructive"
                            disabled={reverse.processing}
                        >
                            Reverse commission
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}
