import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { VoiceButton } from '@/components/crm/voice-button';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    AtRisk,
    type AlertPrefs,
    type AtRiskItem,
} from '@/components/deals/at-risk';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Deal = {
    ulid: string;
    street: string;
    city: string | null;
    type: string;
    status: string;
    price_cents: number;
    closing_date: string | null;
    agent: string | null;
    missing: number;
};
type Forecast = {
    buckets: Record<
        string,
        {
            days: number;
            deals: number;
            company_cents: number;
            gci_cents: number;
        }
    >;
    agents: { name: string; deals: number; company_cents: number }[];
} | null;
type Props = {
    deals: Deal[];
    canCreateFor: { ulid: string; name: string }[];
    forecast?: Forecast;
    atRisk: AtRiskItem[];
    alerts: AlertPrefs;
    canBringOverFiles?: boolean;
    dealIqEmail?: string | null;
};

const columns: [string, string][] = [
    ['pre_contract', 'Pre-contract'],
    ['under_contract', 'Under contract'],
    ['closed', 'Closed'],
    ['fell_through', 'Fell through'],
];
const typeLabel: Record<string, string> = {
    listing: 'Listing',
    buyer: 'Buyer',
    dual: 'Dual',
    lease: 'Lease',
    referral: 'Referral',
    new_construction: 'New construction',
};

/** The deal pipeline. */
export default function Deals({
    deals,
    canCreateFor,
    forecast,
    atRisk,
    alerts,
    canBringOverFiles = false,
    dealIqEmail = null,
}: Props) {
    const params = new URLSearchParams(
        typeof window !== 'undefined' ? window.location.search : '',
    );
    const [creating, setCreating] = useState(params.has('contact'));
    const form = useForm({
        type: 'buyer',
        street: '',
        city: '',
        state_code: '',
        postal_code: '',
        mls_number: '',
        price: '',
        agent: '',
        contact: params.get('contact') ?? '',
    });
    const contractFile = useRef<HTMLInputElement>(null);
    const intake = useForm<{ file: File | null }>({ file: null });
    const describe = useForm({ text: '' });

    return (
        <>
            <Head title="Deals" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Deals"
                    description="Every deal from first contact to closing."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {canBringOverFiles && (
                                <Button variant="outline" asChild>
                                    <Link href="/deals/bring-over-files">
                                        Switch from another app
                                    </Link>
                                </Button>
                            )}
                            <Button onClick={() => setCreating(!creating)}>
                                New deal
                            </Button>
                        </div>
                    }
                />
                <section
                    className="grid gap-3 rounded-lg border p-4 text-sm"
                    aria-label="Start a deal with Deal-IQ"
                    id="start"
                    data-tour="deal-start"
                >
                    <p className="font-medium">Start a deal the fast way</p>
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            ref={contractFile}
                            type="file"
                            className="hidden"
                            accept="application/pdf,image/*"
                            capture="environment"
                            aria-label="Contract file or photo"
                            onChange={(e) => {
                                const f = e.target.files?.[0];
                                if (f) {
                                    intake.transform(() => ({ file: f }));
                                    intake.post('/deals/intake', {
                                        forceFormData: true,
                                    });
                                }
                            }}
                        />
                        <Button
                            variant="outline"
                            disabled={intake.processing}
                            onClick={() => contractFile.current?.click()}
                        >
                            {intake.processing
                                ? 'Uploading…'
                                : 'Start from a contract (file or photo)'}
                        </Button>
                        <span className="text-muted-foreground">or</span>
                        <form
                            className="flex min-w-64 flex-1 flex-wrap gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                describe.post('/deals/intake/text');
                            }}
                        >
                            <Input
                                aria-label="Describe the new deal"
                                className="min-w-56 flex-1"
                                placeholder='e.g. "Buyer deal, the Garcias, 412 Oak St Austin TX, $450k, closing Dec 18"'
                                value={describe.data.text}
                                onChange={(e) =>
                                    describe.setData('text', e.target.value)
                                }
                            />
                            <VoiceButton
                                onText={(t) => describe.setData('text', t)}
                                label="Describe it out loud"
                            />
                            <Button
                                type="submit"
                                disabled={
                                    describe.processing ||
                                    describe.data.text.trim() === ''
                                }
                            >
                                Set it up
                            </Button>
                        </form>
                    </div>
                    {(intake.errors.file || describe.errors.text) && (
                        <p role="alert" className="text-destructive">
                            {intake.errors.file ?? describe.errors.text}
                        </p>
                    )}
                    {dealIqEmail && (
                        <p className="text-sm">
                            Or forward the signed contract by email to{' '}
                            <strong className="break-all">{dealIqEmail}</strong>{' '}
                            <button
                                type="button"
                                className="underline"
                                onClick={() =>
                                    void navigator.clipboard?.writeText(
                                        dealIqEmail,
                                    )
                                }
                            >
                                Copy
                            </button>
                            . Only emails you send from your RightAlly email
                            address are read.
                        </p>
                    )}
                </section>
                <AtRisk items={atRisk} alerts={alerts} />
                <nav
                    aria-label="Deal boards"
                    className="flex flex-wrap gap-2 text-sm"
                >
                    <Button asChild variant="outline" size="sm">
                        <Link href="/deals/listings">Listings board</Link>
                    </Button>
                    {forecast && (
                        <Button asChild variant="outline" size="sm">
                            <Link href="/deals/money">
                                Money coming in and royalties
                            </Link>
                        </Button>
                    )}
                </nav>
                <span data-tour="deal-list" />
                {forecast && (
                    <section className="grid gap-2 rounded-lg border p-4 text-sm">
                        <p className="font-medium">
                            Company dollar expected from deals under contract
                        </p>
                        <div className="grid gap-2 sm:grid-cols-3">
                            {Object.values(forecast.buckets).map((b) => (
                                <div key={b.days}>
                                    <p className="text-muted-foreground">
                                        Next {b.days} days
                                    </p>
                                    <p className="text-xl font-semibold">
                                        {usd(b.company_cents)}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {b.deals} deal(s)
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>
                )}
                {creating && (
                    <form
                        className="grid gap-2 rounded-lg border p-4 text-sm md:grid-cols-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/deals');
                        }}
                    >
                        <select
                            aria-label="Kind of deal"
                            className="rounded border bg-background px-2 py-1.5"
                            value={form.data.type}
                            onChange={(e) =>
                                form.setData('type', e.target.value)
                            }
                        >
                            {Object.entries(typeLabel).map(([v, l]) => (
                                <option key={v} value={v}>
                                    {l}
                                </option>
                            ))}
                        </select>
                        <Input
                            aria-label="Street address"
                            placeholder="123 Main St"
                            className="md:col-span-2"
                            value={form.data.street}
                            onChange={(e) =>
                                form.setData('street', e.target.value)
                            }
                        />
                        <Input
                            aria-label="City"
                            placeholder="City"
                            value={form.data.city}
                            onChange={(e) =>
                                form.setData('city', e.target.value)
                            }
                        />
                        <Input
                            aria-label="State"
                            placeholder="TX"
                            maxLength={2}
                            value={form.data.state_code}
                            onChange={(e) =>
                                form.setData(
                                    'state_code',
                                    e.target.value.toUpperCase(),
                                )
                            }
                        />
                        <Input
                            aria-label="ZIP"
                            placeholder="ZIP"
                            value={form.data.postal_code}
                            onChange={(e) =>
                                form.setData('postal_code', e.target.value)
                            }
                        />
                        <Input
                            aria-label="MLS number"
                            placeholder="MLS # (optional)"
                            value={form.data.mls_number}
                            onChange={(e) =>
                                form.setData('mls_number', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Price ($)"
                            placeholder="Price $"
                            type="number"
                            value={form.data.price}
                            onChange={(e) =>
                                form.setData('price', e.target.value)
                            }
                        />
                        {canCreateFor.length > 0 && (
                            <select
                                aria-label="Agent"
                                className="rounded border bg-background px-2 py-1.5"
                                value={form.data.agent}
                                onChange={(e) =>
                                    form.setData('agent', e.target.value)
                                }
                            >
                                <option value="">Me</option>
                                {canCreateFor.map((a) => (
                                    <option key={a.ulid} value={a.ulid}>
                                        {a.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        {form.data.contact && (
                            <span className="self-center text-muted-foreground md:col-span-2">
                                The client from your contacts is added as a
                                party.
                            </span>
                        )}
                        {Object.values(form.errors).length > 0 && (
                            <p
                                role="alert"
                                className="text-destructive md:col-span-4"
                            >
                                {Object.values(form.errors)[0]}
                            </p>
                        )}
                        <Button
                            type="submit"
                            className="justify-self-start"
                            disabled={form.processing}
                        >
                            Create deal
                        </Button>
                    </form>
                )}
                <div className="grid gap-3 md:grid-cols-4">
                    {columns.map(([status, label]) => (
                        <section
                            key={status}
                            className="grid content-start gap-2 rounded-lg bg-muted/30 p-2"
                        >
                            <h2 className="px-1 text-sm font-semibold">
                                {label} (
                                {
                                    deals.filter((d) => d.status === status)
                                        .length
                                }
                                )
                            </h2>
                            {deals
                                .filter((d) => d.status === status)
                                .map((d) => (
                                    <Link
                                        key={d.ulid}
                                        href={`/deals/${d.ulid}`}
                                        className="grid gap-1 rounded-md border bg-background p-3 text-sm hover:border-primary"
                                    >
                                        <span className="font-medium">
                                            {d.street}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {typeLabel[d.type]} · {d.agent}
                                            {d.price_cents
                                                ? ` · ${usd(d.price_cents)}`
                                                : ''}
                                        </span>
                                        {d.closing_date && (
                                            <span className="text-xs">
                                                Closing{' '}
                                                {new Date(
                                                    d.closing_date,
                                                ).toLocaleDateString()}
                                            </span>
                                        )}
                                        {d.missing > 0 &&
                                            d.status !== 'closed' && (
                                                <Badge
                                                    variant="outline"
                                                    className="justify-self-start"
                                                >
                                                    {d.missing} still needed
                                                </Badge>
                                            )}
                                    </Link>
                                ))}
                        </section>
                    ))}
                </div>
            </div>
        </>
    );
}

Deals.layout = { breadcrumbs: [{ title: 'Deals', href: '/deals' }] };
