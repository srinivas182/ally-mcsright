import { Head, Link, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    mine: {
        ulid: string;
        kind: string;
        contact: string;
        status: string;
        agreement_status: string;
        updated_at: string;
    }[];
    contact: { ulid: string; name: string } | null;
    recent: { ulid: string; name: string }[];
};

/** AP1: start a guided listing appointment or buyer consultation. */
export default function Appointments({ mine, contact, recent }: Props) {
    const form = useForm({
        kind: 'listing',
        contact: contact?.ulid ?? recent[0]?.ulid ?? '',
    });

    return (
        <>
            <Head title="Appointments" />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Appointments"
                    description="A guided listing appointment or buyer consultation, step by step, on a laptop or tablet beside your client. It ends with the listing created or the buyer agreement recorded, and a summary emailed to them."
                />
                <form
                    className="grid gap-3 rounded-lg border p-4 sm:grid-cols-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/appointments');
                    }}
                >
                    <label className="grid gap-1">
                        What kind
                        <select
                            className="rounded-md border bg-background p-2 text-base"
                            value={form.data.kind}
                            onChange={(e) =>
                                form.setData('kind', e.target.value)
                            }
                        >
                            <option value="listing">
                                Listing appointment (seller)
                            </option>
                            <option value="buyer">Buyer consultation</option>
                        </select>
                    </label>
                    <label className="grid gap-1">
                        With
                        <select
                            className="rounded-md border bg-background p-2 text-base"
                            value={form.data.contact}
                            onChange={(e) =>
                                form.setData('contact', e.target.value)
                            }
                        >
                            {contact && (
                                <option value={contact.ulid}>
                                    {contact.name}
                                </option>
                            )}
                            {recent
                                .filter((r) => r.ulid !== contact?.ulid)
                                .map((r) => (
                                    <option key={r.ulid} value={r.ulid}>
                                        {r.name}
                                    </option>
                                ))}
                        </select>
                    </label>
                    <div className="flex items-end">
                        <Button
                            size="lg"
                            disabled={
                                form.processing || form.data.contact === ''
                            }
                        >
                            Start
                        </Button>
                    </div>
                </form>
                <ul className="grid gap-2">
                    {mine.map((m) => (
                        <li key={m.ulid}>
                            <Link
                                href={`/appointments/${m.ulid}`}
                                className="flex flex-wrap items-center gap-2 rounded-lg border p-3 hover:bg-muted/50"
                            >
                                <span className="font-medium">{m.contact}</span>
                                <span className="text-muted-foreground">
                                    {m.kind === 'listing'
                                        ? 'Listing appointment'
                                        : 'Buyer consultation'}
                                </span>
                                <span className="ml-auto flex gap-2">
                                    {m.kind === 'buyer' && (
                                        <Badge
                                            variant={
                                                m.agreement_status === 'signed'
                                                    ? 'default'
                                                    : 'destructive'
                                            }
                                        >
                                            {m.agreement_status === 'signed'
                                                ? 'Agreement signed'
                                                : 'Agreement not signed'}
                                        </Badge>
                                    )}
                                    <Badge variant="outline">
                                        {m.status === 'done'
                                            ? 'Done'
                                            : 'In progress'}
                                    </Badge>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}
