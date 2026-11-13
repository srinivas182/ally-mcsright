import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import {
    NegotiationPanel,
    type NegotiationStep,
    type PacketChange,
} from '@/components/forms/negotiation-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Field = { key: string; type: string; label: string; required: boolean };
type FormRow = {
    id: number;
    name: string;
    values: Record<string, string>;
    fields: Field[];
};
type Signer = {
    ulid?: string;
    role: string;
    name: string;
    email: string;
    phone: string | null;
    sign_order: number;
    status: string;
    signed_at?: string | null;
    decline_reason?: string | null;
};
type Props = {
    packet: {
        ulid: string;
        title: string;
        status: string;
        kind: string;
        round: number;
        deal: { ulid: string; street: string };
    };
    forms: FormRow[];
    signers: Signer[];
    problems: string[];
    clauses: { title: string; body: string }[];
    events: { action: string; detail: string | null; at: string }[];
    negotiation: NegotiationStep[];
    changes: PacketChange[];
    templates: { ulid: string; name: string }[];
};

function FormValues({
    packet,
    form,
    clauses,
    locked,
}: {
    packet: string;
    form: FormRow;
    clauses: Props['clauses'];
    locked: boolean;
}) {
    const [values, setValues] = useState<Record<string, string>>({
        ...form.values,
    });

    return (
        <section className="grid gap-2 rounded-lg border p-4 text-sm">
            <div className="flex items-center justify-between gap-2">
                <h2 className="font-semibold">{form.name}</h2>
                <a
                    className="underline"
                    href={`/forms/packets/${packet}/forms/${form.id}/preview`}
                    target="_blank"
                    rel="noreferrer"
                >
                    Preview PDF
                </a>
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
                {form.fields.map((f) => (
                    <label key={f.key} className="grid gap-1">
                        <span className="text-xs text-muted-foreground">
                            {f.label}
                            {f.required ? ' *' : ''}
                        </span>
                        {f.type === 'checkbox' ? (
                            <input
                                type="checkbox"
                                className="size-4"
                                disabled={locked}
                                checked={values[f.key] === '1'}
                                onChange={(e) =>
                                    setValues({
                                        ...values,
                                        [f.key]: e.target.checked ? '1' : '',
                                    })
                                }
                            />
                        ) : (
                            <Input
                                aria-label={f.label}
                                disabled={locked}
                                type={f.type === 'date' ? 'text' : 'text'}
                                value={values[f.key] ?? ''}
                                onChange={(e) =>
                                    setValues({
                                        ...values,
                                        [f.key]: e.target.value,
                                    })
                                }
                            />
                        )}
                    </label>
                ))}
            </div>
            {!locked &&
                clauses.length > 0 &&
                form.fields.some((f) => f.type === 'text') && (
                    <select
                        aria-label="Insert a clause"
                        className="justify-self-start rounded border bg-background px-2 py-1.5"
                        defaultValue=""
                        onChange={(e) => {
                            const clause = clauses[Number(e.target.value)];
                            const target =
                                form.fields.find(
                                    (f) =>
                                        f.type === 'text' &&
                                        (values[f.key] ?? '') === '',
                                ) ?? form.fields.find((f) => f.type === 'text');
                            if (clause && target)
                                setValues({
                                    ...values,
                                    [target.key]: `${values[target.key] ?? ''}${values[target.key] ? ' ' : ''}${clause.body}`,
                                });
                            e.target.value = '';
                        }}
                    >
                        <option value="">Insert a clause…</option>
                        {clauses.map((c, i) => (
                            <option key={c.title} value={i}>
                                {c.title}
                            </option>
                        ))}
                    </select>
                )}
            {!locked && (
                <Button
                    size="sm"
                    className="justify-self-start"
                    onClick={() =>
                        router.post(
                            `/forms/packets/${packet}/forms/${form.id}/values`,
                            { values },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save
                </Button>
            )}
        </section>
    );
}

/** One packet: fill the forms, choose signers, check before sending. */
export default function Packet({
    packet,
    forms,
    signers,
    problems,
    clauses,
    events,
    negotiation,
    changes,
    templates,
}: Props) {
    const locked = packet.status !== 'draft';
    const signerForm = useForm<{ signers: Omit<Signer, 'status'>[] }>({
        signers: signers.map(({ role, name, email, phone, sign_order }) => ({
            role,
            name,
            email,
            phone,
            sign_order,
        })),
    });
    const setSigner = (i: number, patch: Partial<Signer>) =>
        signerForm.setData(
            'signers',
            signerForm.data.signers.map((s, j) =>
                j === i ? { ...s, ...patch } : s,
            ),
        );

    return (
        <>
            <Head title={packet.title} />
            <div className="flex max-w-4xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title={packet.title}
                    description={packet.deal.street}
                    actions={
                        <Link
                            className="text-sm underline"
                            href={`/forms/deals/${packet.deal.ulid}`}
                        >
                            All forms for this deal
                        </Link>
                    }
                />
                <section
                    className={`grid gap-1 rounded-lg border p-4 text-sm ${problems.length ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-emerald-300 bg-emerald-50 text-emerald-900'}`}
                    role="status"
                >
                    <p className="font-medium">
                        {problems.length
                            ? `Before sending: ${problems.length} thing(s) to fix`
                            : 'Ready to send: nothing missing.'}
                    </p>
                    {problems.map((p) => (
                        <p key={p}>• {p}</p>
                    ))}
                </section>
                {negotiation.find((n) => n.ulid === packet.ulid)?.changes
                    .length ? (
                    <section className="grid gap-1 rounded-lg border border-sky-300 bg-sky-50/50 p-4 text-sm">
                        <p className="font-medium capitalize">
                            {packet.kind} — changed terms
                        </p>
                        {negotiation
                            .find((n) => n.ulid === packet.ulid)
                            ?.changes.map((c) => (
                                <p key={c}>• {c}</p>
                            ))}
                    </section>
                ) : null}
                {forms.map((f) => (
                    <FormValues
                        key={f.id}
                        packet={packet.ulid}
                        form={f}
                        clauses={clauses}
                        locked={locked}
                    />
                ))}
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="flex items-center gap-2 font-semibold">
                        Signers{' '}
                        <Badge variant="outline" className="capitalize">
                            {packet.status}
                        </Badge>
                    </h2>
                    {signerForm.data.signers.map((s, i) => (
                        <div key={i} className="flex flex-wrap gap-2">
                            <Input
                                aria-label="Order"
                                type="number"
                                min={1}
                                className="w-16"
                                disabled={locked}
                                value={s.sign_order}
                                onChange={(e) =>
                                    setSigner(i, {
                                        sign_order: Number(e.target.value),
                                    })
                                }
                            />
                            <Input
                                aria-label="Role"
                                placeholder="Role (buyer1)"
                                className="w-32"
                                disabled={locked}
                                value={s.role}
                                onChange={(e) =>
                                    setSigner(i, { role: e.target.value })
                                }
                            />
                            <Input
                                aria-label="Name"
                                placeholder="Name"
                                className="w-44"
                                disabled={locked}
                                value={s.name}
                                onChange={(e) =>
                                    setSigner(i, { name: e.target.value })
                                }
                            />
                            <Input
                                aria-label="Email"
                                placeholder="Email"
                                className="w-56"
                                disabled={locked}
                                value={s.email}
                                onChange={(e) =>
                                    setSigner(i, { email: e.target.value })
                                }
                            />
                            {!locked && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        signerForm.setData(
                                            'signers',
                                            signerForm.data.signers.filter(
                                                (_, j) => j !== i,
                                            ),
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            )}
                        </div>
                    ))}
                    {!locked && (
                        <span className="flex gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    signerForm.setData('signers', [
                                        ...signerForm.data.signers,
                                        {
                                            role: '',
                                            name: '',
                                            email: '',
                                            phone: null,
                                            sign_order:
                                                signerForm.data.signers.length +
                                                1,
                                        },
                                    ])
                                }
                            >
                                Add signer
                            </Button>
                            <Button
                                size="sm"
                                onClick={() =>
                                    signerForm.post(
                                        `/forms/packets/${packet.ulid}/signers`,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Save signers
                            </Button>
                        </span>
                    )}
                    {Object.values(signerForm.errors)[0] && (
                        <p role="alert" className="text-destructive">
                            {Object.values(signerForm.errors)[0]}
                        </p>
                    )}
                    <SendPanel
                        packet={packet}
                        signers={signers}
                        events={events}
                        ready={problems.length === 0}
                    />
                </section>
                <NegotiationPanel
                    packet={packet}
                    forms={forms}
                    negotiation={negotiation}
                    changes={changes}
                    templates={templates}
                />
            </div>
        </>
    );
}

function SendPanel({
    packet,
    signers,
    events,
    ready,
}: {
    packet: Props['packet'];
    signers: Signer[];
    events: Props['events'];
    ready: boolean;
}) {
    const [verify, setVerify] = useState('email');
    const [reason, setReason] = useState('');
    const opts = { preserveScroll: true };

    return (
        <div className="grid gap-2 border-t pt-2">
            {packet.status === 'draft' && (
                <span className="flex flex-wrap items-center gap-2">
                    <select
                        aria-label="How signers prove who they are"
                        className="rounded border bg-background px-2 py-1.5"
                        value={verify}
                        onChange={(e) => setVerify(e.target.value)}
                    >
                        <option value="email">Email link</option>
                        <option value="sms">Email link and text code</option>
                    </select>
                    <Button
                        size="sm"
                        disabled={!ready}
                        onClick={() =>
                            router.post(
                                `/forms/packets/${packet.ulid}/send`,
                                { verify },
                                opts,
                            )
                        }
                    >
                        Send for signature
                    </Button>
                </span>
            )}
            {packet.status !== 'draft' && (
                <ul className="grid gap-1">
                    {signers.map((s) => (
                        <li
                            key={s.ulid ?? s.email}
                            className="flex flex-wrap items-center gap-2"
                        >
                            {s.sign_order}. {s.name}{' '}
                            <Badge
                                variant={
                                    s.status === 'signed'
                                        ? 'secondary'
                                        : 'outline'
                                }
                                className="capitalize"
                            >
                                {s.status}
                            </Badge>
                            {s.decline_reason && (
                                <span className="text-destructive">
                                    “{s.decline_reason}”
                                </span>
                            )}
                            {packet.status === 'sent' &&
                                s.status !== 'signed' &&
                                s.ulid && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.post(
                                                `/forms/packets/${packet.ulid}/in-person/${s.ulid}`,
                                            )
                                        }
                                    >
                                        Sign in person now
                                    </Button>
                                )}
                        </li>
                    ))}
                </ul>
            )}
            {packet.status === 'completed' && (
                <a
                    className="underline"
                    href={`/forms/packets/${packet.ulid}/signed.pdf`}
                >
                    Download the signed, sealed copy
                </a>
            )}
            {(packet.status === 'sent' || packet.status === 'declined') && (
                <span className="flex flex-wrap gap-2">
                    <Input
                        aria-label="Reason to void"
                        placeholder="Reason to void"
                        className="w-56"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={reason.length < 3}
                        onClick={() =>
                            router.post(
                                `/forms/packets/${packet.ulid}/void`,
                                { reason },
                                opts,
                            )
                        }
                    >
                        Void
                    </Button>
                </span>
            )}
            {events.length > 0 && (
                <details>
                    <summary className="cursor-pointer text-muted-foreground">
                        Activity ({events.length})
                    </summary>
                    <ul className="mt-1 grid gap-0.5 text-xs text-muted-foreground">
                        {events.map((e, i) => (
                            <li key={i}>
                                {e.at} · {e.action}
                                {e.detail ? ` · ${e.detail}` : ''}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}
