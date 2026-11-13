import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

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
    role: string;
    name: string;
    email: string;
    phone: string | null;
    sign_order: number;
    status: string;
};
type Props = {
    packet: {
        ulid: string;
        title: string;
        status: string;
        deal: { ulid: string; street: string };
    };
    forms: FormRow[];
    signers: Signer[];
    problems: string[];
    clauses: { title: string; body: string }[];
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
}: Props) {
    const locked = packet.status !== 'draft';
    const signerForm = useForm<{ signers: Omit<Signer, 'status'>[] }>({
        signers: signers.map(({ status: _s, ...rest }) => rest),
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
                    <p className="text-xs text-muted-foreground">
                        Sending for signature arrives in the next release.
                    </p>
                </section>
            </div>
        </>
    );
}
