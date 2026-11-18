import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import {
    AppointmentRecorder,
    type RecordingProps,
} from '@/components/appointments/recorder';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Answers = Record<string, Record<string, string | number | boolean | null>>;
type C = {
    ulid: string;
    kind: 'listing' | 'buyer';
    status: string;
    steps: Record<string, string>;
    answers: Answers;
    contact: { ulid: string; name: string; email: string | null } | null;
    agreement: {
        status: string;
        signed_on: string | null;
        ends_on: string | null;
        has_file: boolean;
    };
    deal: string | null;
    guide_sent_at: string | null;
    marketing_options?: Record<string, string>;
    cmas?: {
        ulid: string;
        title: string;
        low_cents: number | null;
        likely_cents: number | null;
        high_cents: number | null;
    }[];
    net?: {
        price_cents: number;
        net_cents: number;
        lines: { key: string; label: string; cents: number; note: string }[];
    } | null;
};

/** AP1: the guided appointment, one plain step at a time. Every step saves as you go. */
export default function Consultation({
    c,
    recording,
}: {
    c: C;
    recording: RecordingProps;
}) {
    const keys = Object.keys(c.steps);
    const [step, setStep] = useState(keys[0]);
    const i = keys.indexOf(step);

    return (
        <>
            <Head
                title={
                    c.kind === 'listing'
                        ? 'Listing appointment'
                        : 'Buyer consultation'
                }
            />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-6">
                <PageHeader
                    title={`${c.kind === 'listing' ? 'Listing appointment' : 'Buyer consultation'} with ${c.contact?.name ?? ''}`}
                    description="Go through it together. Everything saves as you go."
                />
                <ol className="flex flex-wrap gap-2" aria-label="Steps">
                    {keys.map((k, n) => (
                        <li key={k}>
                            <Button
                                size="sm"
                                variant={k === step ? 'default' : 'outline'}
                                onClick={() => setStep(k)}
                                aria-current={k === step ? 'step' : undefined}
                            >
                                {n + 1}. {c.steps[k]}
                            </Button>
                        </li>
                    ))}
                </ol>
                <section className="grid gap-4 rounded-lg border p-5 text-base">
                    <h2 className="text-xl font-semibold">{c.steps[step]}</h2>
                    <Step c={c} step={step} />
                </section>
                <AppointmentRecorder
                    consultation={c.ulid}
                    recording={recording}
                />
                <div className="flex justify-between">
                    <Button
                        variant="outline"
                        disabled={i === 0}
                        onClick={() => setStep(keys[i - 1])}
                    >
                        Back
                    </Button>
                    {i < keys.length - 1 && (
                        <Button onClick={() => setStep(keys[i + 1])}>
                            Next
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}

function Fields({
    c,
    step,
    fields,
}: {
    c: C;
    step: string;
    fields: { key: string; label: string; type?: string; hint?: string }[];
}) {
    const initial = Object.fromEntries(
        fields.map((f) => [
            f.key,
            c.answers[step]?.[f.key] ?? (f.type === 'checkbox' ? false : ''),
        ]),
    );
    const form =
        useForm<Record<string, string | number | boolean | null>>(initial);
    const save = () =>
        router.post(
            `/appointments/${c.ulid}/save`,
            { step, values: form.data },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <div className="grid gap-3">
            {fields.map((f) =>
                f.type === 'checkbox' ? (
                    <label key={f.key} className="flex items-center gap-3">
                        <input
                            type="checkbox"
                            className="size-5"
                            checked={Boolean(form.data[f.key])}
                            onChange={(e) =>
                                form.setData(f.key, e.target.checked)
                            }
                            onBlur={save}
                        />
                        {f.label}
                    </label>
                ) : (
                    <label key={f.key} className="grid gap-1">
                        {f.label}
                        <Input
                            type={f.type ?? 'text'}
                            className="text-base"
                            value={String(form.data[f.key] ?? '')}
                            onChange={(e) =>
                                form.setData(f.key, e.target.value)
                            }
                            onBlur={save}
                        />
                        {f.hint && (
                            <span className="text-sm text-muted-foreground">
                                {f.hint}
                            </span>
                        )}
                    </label>
                ),
            )}
            <div>
                <Button variant="outline" onClick={save}>
                    Save
                </Button>
            </div>
        </div>
    );
}

function Step({ c, step }: { c: C; step: string }) {
    if (c.kind === 'listing') {
        switch (step) {
            case 'home':
                return (
                    <Fields
                        c={c}
                        step="home"
                        fields={[
                            { key: 'street', label: 'Street address' },
                            { key: 'city', label: 'City' },
                            { key: 'state', label: 'State (two letters)' },
                            { key: 'zip', label: 'ZIP' },
                            { key: 'beds', label: 'Bedrooms', type: 'number' },
                            {
                                key: 'baths',
                                label: 'Bathrooms',
                                type: 'number',
                            },
                            {
                                key: 'sqft',
                                label: 'Square feet (approx.)',
                                type: 'number',
                            },
                            {
                                key: 'updates',
                                label: 'Updates and special features',
                            },
                        ]}
                    />
                );
            case 'price':
                return (
                    <div className="grid gap-3">
                        {(c.cmas ?? []).length === 0 ? (
                            <p className="text-muted-foreground">
                                No CMA for this seller yet.{' '}
                                <a className="underline" href="/pricing">
                                    Make one in CMA and estimates
                                </a>
                                , then come back.
                            </p>
                        ) : (
                            <ul className="grid gap-2">
                                {(c.cmas ?? []).map((m) => (
                                    <li
                                        key={m.ulid}
                                        className="rounded-md border p-3"
                                    >
                                        <a
                                            className="font-medium underline"
                                            href={`/pricing/cma/${m.ulid}`}
                                        >
                                            {m.title}
                                        </a>
                                        {m.likely_cents !== null && (
                                            <span>
                                                {' '}
                                                · likely {usd(m.likely_cents)} (
                                                {usd(m.low_cents ?? 0)} –{' '}
                                                {usd(m.high_cents ?? 0)})
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Fields
                            c={c}
                            step="price"
                            fields={[
                                {
                                    key: 'list_price',
                                    label: 'List price we agreed ($)',
                                    type: 'number',
                                },
                            ]}
                        />
                    </div>
                );
            case 'net':
                return (
                    <div className="grid gap-3">
                        <Fields
                            c={c}
                            step="net"
                            fields={[
                                {
                                    key: 'payoff',
                                    label: 'Mortgage still owed ($)',
                                    type: 'number',
                                    hint: "From the seller's lender statement. Stays private.",
                                },
                            ]}
                        />
                        {c.net ? (
                            <table className="w-full">
                                <tbody>
                                    <tr className="border-b">
                                        <td className="py-1.5">Price</td>
                                        <td className="py-1.5 text-right">
                                            {usd(c.net.price_cents)}
                                        </td>
                                    </tr>
                                    {c.net.lines
                                        .filter((l) => l.cents !== 0)
                                        .map((l) => (
                                            <tr
                                                key={l.key}
                                                className="border-b"
                                            >
                                                <td className="py-1.5">
                                                    {l.label}
                                                    <span className="block text-sm text-muted-foreground">
                                                        {l.note}
                                                    </span>
                                                </td>
                                                <td className="py-1.5 text-right">
                                                    {usd(l.cents)}
                                                </td>
                                            </tr>
                                        ))}
                                    <tr className="text-lg font-semibold">
                                        <td className="py-2">
                                            You walk away with about
                                        </td>
                                        <td className="py-2 text-right">
                                            {usd(c.net.net_cents)}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        ) : (
                            <p className="text-muted-foreground">
                                Set the list price first.
                            </p>
                        )}
                    </div>
                );
            case 'marketing':
                return (
                    <Fields
                        c={c}
                        step="marketing"
                        fields={Object.entries(c.marketing_options ?? {}).map(
                            ([k, label]) => ({
                                key: k,
                                label,
                                type: 'checkbox',
                            }),
                        )}
                    />
                );
            case 'terms':
                return (
                    <div className="grid gap-3">
                        <Fields
                            c={c}
                            step="terms"
                            fields={[
                                {
                                    key: 'listing_pct',
                                    label: 'Listing brokerage commission (%)',
                                    type: 'number',
                                    hint: 'Not set by law; fully negotiable.',
                                },
                                {
                                    key: 'months',
                                    label: 'How long the listing agreement lasts (months)',
                                    type: 'number',
                                },
                                {
                                    key: 'buyer_pct',
                                    label: "Offer to the buyer's agent (%), if the seller chooses",
                                    type: 'number',
                                    hint: "The seller's choice. It is never shown on the MLS.",
                                },
                            ]}
                        />
                    </div>
                );
            default:
                return <Finish c={c} />;
        }
    }
    switch (step) {
        case 'needs':
            return (
                <Fields
                    c={c}
                    step="needs"
                    fields={[
                        { key: 'areas', label: 'Areas or ZIP codes' },
                        {
                            key: 'beds',
                            label: 'Bedrooms (at least)',
                            type: 'number',
                        },
                        { key: 'must_haves', label: 'Must-haves' },
                        { key: 'timeline', label: 'When they want to move' },
                    ]}
                />
            );
        case 'money':
            return (
                <Fields
                    c={c}
                    step="money"
                    fields={[
                        {
                            key: 'budget',
                            label: 'Top budget ($)',
                            type: 'number',
                        },
                        {
                            key: 'preapproved',
                            label: 'Pre-approved by a lender',
                            type: 'checkbox',
                        },
                        { key: 'lender', label: 'Lender (if they have one)' },
                    ]}
                />
            );
        case 'agreement':
            return <Agreement c={c} />;
        case 'sign':
            return <Sign c={c} />;
        default:
            return <Finish c={c} />;
    }
}

function Agreement({ c }: { c: C }) {
    const a = c.answers.agreement ?? {};
    const form = useForm({
        comp_kind: String(a.comp_kind ?? 'percent'),
        comp_value: String(a.comp_value ?? ''),
        months: String(a.months ?? '3'),
        exclusive: Boolean(a.exclusive ?? true),
        area: String(a.area ?? ''),
    });

    return (
        <form
            className="grid gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(
                    `/appointments/${c.ulid}/save`,
                    {
                        step: 'agreement',
                        values: {
                            ...form.data,
                            comp_value: Number(form.data.comp_value),
                            months: Number(form.data.months),
                        },
                    },
                    {
                        preserveScroll: true,
                        onError: (err) =>
                            form.setError(err as Record<string, string>),
                    },
                );
            }}
        >
            <p className="text-muted-foreground">
                Since August 2024, you need a written agreement with a buyer
                before touring homes. It must say exactly how you&rsquo;re paid
                (a percent or a dollar amount, never open-ended). The buyer
                never pays more than agreed, and a seller can pay some or all of
                it.
            </p>
            <div className="flex flex-wrap items-end gap-3">
                <label className="grid gap-1">
                    Paid as
                    <select
                        className="rounded-md border bg-background p-2 text-base"
                        value={form.data.comp_kind}
                        onChange={(e) =>
                            form.setData('comp_kind', e.target.value)
                        }
                    >
                        <option value="percent">Percent of the price</option>
                        <option value="flat">A dollar amount</option>
                    </select>
                </label>
                <label className="grid gap-1">
                    {form.data.comp_kind === 'flat'
                        ? 'Amount ($)'
                        : 'Percent (%)'}
                    <Input
                        type="number"
                        step="0.01"
                        className="w-32 text-base"
                        value={form.data.comp_value}
                        onChange={(e) =>
                            form.setData('comp_value', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1">
                    Months
                    <Input
                        type="number"
                        className="w-24 text-base"
                        value={form.data.months}
                        onChange={(e) => form.setData('months', e.target.value)}
                    />
                </label>
            </div>
            <label className="flex items-center gap-3">
                <input
                    type="checkbox"
                    className="size-5"
                    checked={form.data.exclusive}
                    onChange={(e) =>
                        form.setData('exclusive', e.target.checked)
                    }
                />
                Exclusive (they work only with you during the agreement)
            </label>
            <label className="grid gap-1">
                Area it covers (optional)
                <Input
                    className="text-base"
                    value={form.data.area}
                    onChange={(e) => form.setData('area', e.target.value)}
                />
            </label>
            {(form.errors.comp_value || form.errors.months) && (
                <p className="text-destructive">
                    {form.errors.comp_value ?? form.errors.months}
                </p>
            )}
            <div>
                <Button>Save the agreement terms</Button>
            </div>
        </form>
    );
}

function Sign({ c }: { c: C }) {
    const form = useForm<{ signed_on: string; file: File | null }>({
        signed_on: new Date().toISOString().slice(0, 10),
        file: null,
    });
    if (c.agreement.status === 'signed') {
        return (
            <p className="flex flex-wrap items-center gap-2">
                <Badge>Signed {c.agreement.signed_on}</Badge> Good until{' '}
                {c.agreement.ends_on}.
                {c.agreement.has_file && (
                    <a
                        className="underline"
                        href={`/appointments/${c.ulid}/agreement`}
                        target="_blank"
                        rel="noreferrer"
                    >
                        See the signed copy
                    </a>
                )}
            </p>
        );
    }

    return (
        <form
            className="grid gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/appointments/${c.ulid}/signed`, {
                    forceFormData: true,
                    preserveScroll: true,
                });
            }}
        >
            <p className="text-muted-foreground">
                Have them sign your state&rsquo;s buyer agreement form (on paper
                or in Forms), then record it here. Until then, showings for this
                buyer are stopped.
            </p>
            <label className="grid gap-1">
                Signed on
                <Input
                    type="date"
                    className="w-48 text-base"
                    value={form.data.signed_on}
                    onChange={(e) => form.setData('signed_on', e.target.value)}
                />
            </label>
            <label className="grid gap-1">
                The signed copy (PDF)
                <input
                    type="file"
                    accept="application/pdf"
                    onChange={(e) =>
                        form.setData('file', e.target.files?.[0] ?? null)
                    }
                />
            </label>
            {Object.values(form.errors).length > 0 && (
                <p className="text-destructive">
                    {Object.values(form.errors)[0]}
                </p>
            )}
            <div>
                <Button disabled={form.processing}>
                    Record the signed agreement
                </Button>
            </div>
        </form>
    );
}

function Finish({ c }: { c: C }) {
    const [send, setSend] = useState(true);

    return (
        <div className="grid gap-3">
            {c.kind === 'listing' ? (
                <p>
                    {c.deal
                        ? 'The listing is created.'
                        : 'This creates the listing deal (with the agreement end date for the Listings board) and opens it.'}
                </p>
            ) : (
                <p>
                    {c.agreement.status === 'signed'
                        ? 'The agreement is signed. You can book tours.'
                        : 'The agreement is not signed yet. Showings stay stopped until it is.'}
                </p>
            )}
            <label className="flex items-center gap-3">
                <input
                    type="checkbox"
                    className="size-5"
                    checked={send}
                    onChange={(e) => setSend(e.target.checked)}
                    disabled={!c.contact?.email}
                />
                Email {c.contact?.name ?? 'them'} a summary{' '}
                {c.contact?.email
                    ? `(${c.contact.email})`
                    : '(no email on file)'}
            </label>
            {c.guide_sent_at && (
                <p className="text-sm text-muted-foreground">
                    Summary sent {c.guide_sent_at.slice(0, 10)}.
                </p>
            )}
            <div>
                <Button
                    size="lg"
                    onClick={() =>
                        router.post(`/appointments/${c.ulid}/finish`, {
                            send_guide: send,
                        })
                    }
                >
                    {c.kind === 'listing' && !c.deal
                        ? 'Create the listing'
                        : 'Finish'}
                </Button>
            </div>
        </div>
    );
}
