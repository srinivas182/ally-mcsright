import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Field = { key: string; type: string; label: string };
type FormRow = {
    id: number;
    name: string;
    values: Record<string, string>;
    fields: Field[];
};
export type NegotiationStep = {
    ulid: string;
    title: string;
    kind: string;
    round: number;
    status: string;
    changes: string[];
};
export type PacketChange = {
    line: string;
    reason: string;
    at: string;
    waiting_on: string[];
};

/** Picks one fillable field on one form and a new value for it. */
function FieldPicker({
    forms,
    onPick,
    label,
}: {
    forms: FormRow[];
    onPick: (form: FormRow, field: Field, value: string) => void;
    label: string;
}) {
    const [choice, setChoice] = useState('');
    const [value, setValue] = useState('');
    const [formId, key] = choice.split(':');
    const form = forms.find((f) => String(f.id) === formId);
    const field = form?.fields.find((f) => f.key === key);

    return (
        <span className="flex flex-wrap items-center gap-2">
            <select
                aria-label="Term to change"
                className="rounded border bg-background px-2 py-1.5"
                value={choice}
                onChange={(e) => {
                    setChoice(e.target.value);
                    const [fid, k] = e.target.value.split(':');
                    setValue(
                        forms.find((f) => String(f.id) === fid)?.values[k] ??
                            '',
                    );
                }}
            >
                <option value="">Choose a term…</option>
                {forms.map((f) => (
                    <optgroup key={f.id} label={f.name}>
                        {f.fields.map((x) => (
                            <option key={x.key} value={`${f.id}:${x.key}`}>
                                {x.label}
                            </option>
                        ))}
                    </optgroup>
                ))}
            </select>
            <Input
                aria-label="New value"
                placeholder="New value"
                className="w-56"
                value={value}
                disabled={!field}
                onChange={(e) => setValue(e.target.value)}
            />
            <Button
                size="sm"
                variant="outline"
                disabled={!form || !field}
                onClick={() => {
                    if (form && field) onPick(form, field, value);
                    setChoice('');
                    setValue('');
                }}
            >
                {label}
            </Button>
        </span>
    );
}

type Pending = { form: number; key: string; text: string; value: string };

/**
 * Forms part 4: change terms after sending (earlier signers initial only the changes), draft a counter-offer
 * from this packet, or add an addendum to a signed one — with the whole negotiation's history.
 */
export function NegotiationPanel({
    packet,
    forms,
    negotiation,
    changes,
    templates,
}: {
    packet: { ulid: string; status: string };
    forms: FormRow[];
    negotiation: NegotiationStep[];
    changes: PacketChange[];
    templates: { ulid: string; name: string }[];
}) {
    const [amendments, setAmendments] = useState<Pending[]>([]);
    const [reason, setReason] = useState('');
    const [counter, setCounter] = useState<Pending[]>([]);
    const [addenda, setAddenda] = useState<string[]>([]);
    const opts = { preserveScroll: true };
    const pick =
        (set: React.Dispatch<React.SetStateAction<Pending[]>>) =>
        (form: FormRow, field: Field, value: string) =>
            set((list) => [
                ...list.filter(
                    (p) => !(p.form === form.id && p.key === field.key),
                ),
                {
                    form: form.id,
                    key: field.key,
                    value,
                    text: `${field.label}: ${form.values[field.key] || '(blank)'} → ${value || '(blank)'}`,
                },
            ]);
    const byForm = (list: Pending[]) =>
        list.reduce<Record<number, Record<string, string>>>(
            (acc, p) => ({
                ...acc,
                [p.form]: { ...acc[p.form], [p.key]: p.value },
            }),
            {},
        );
    const canCounter = ['sent', 'declined', 'completed'].includes(
        packet.status,
    );

    if (packet.status === 'draft' && negotiation.length === 0) return null;

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <h2 className="font-semibold">Changes and counter-offers</h2>

            {packet.status === 'sent' && (
                <div className="grid gap-2">
                    <h3 className="font-medium">Change terms now</h3>
                    <p className="text-muted-foreground">
                        Anyone who already signed initials only the changes;
                        everyone else signs the new terms.
                    </p>
                    <FieldPicker
                        forms={forms}
                        label="Add change"
                        onPick={pick(setAmendments)}
                    />
                    {amendments.length > 0 && (
                        <>
                            <ul className="grid gap-0.5">
                                {amendments.map((a) => (
                                    <li key={`${a.form}:${a.key}`}>
                                        • {a.text}
                                    </li>
                                ))}
                            </ul>
                            <span className="flex flex-wrap gap-2">
                                <Input
                                    aria-label="Why the terms changed"
                                    placeholder="Why (signers see this)"
                                    className="w-72"
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                                <Button
                                    size="sm"
                                    disabled={reason.length < 3}
                                    onClick={() => {
                                        const grouped = byForm(amendments);
                                        Object.entries(grouped).forEach(
                                            ([form, values]) =>
                                                router.post(
                                                    `/forms/packets/${packet.ulid}/amend`,
                                                    {
                                                        form: Number(form),
                                                        values,
                                                        reason,
                                                    },
                                                    opts,
                                                ),
                                        );
                                        setAmendments([]);
                                        setReason('');
                                    }}
                                >
                                    Make changes and ask for initials
                                </Button>
                            </span>
                        </>
                    )}
                </div>
            )}

            {changes.length > 0 && (
                <ul className="grid gap-1">
                    {changes.map((c, i) => (
                        <li key={i} className="flex flex-wrap gap-2">
                            <span>{c.line}</span>
                            <span className="text-muted-foreground">
                                · {c.reason}
                            </span>
                            {c.waiting_on.length > 0 ? (
                                <Badge variant="outline">
                                    Waiting on {c.waiting_on.join(', ')}
                                </Badge>
                            ) : (
                                <Badge variant="secondary">Initialled</Badge>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canCounter && (
                <div className="grid gap-2 border-t pt-2">
                    <h3 className="font-medium">Counter-offer</h3>
                    <p className="text-muted-foreground">
                        Drafts a new packet from this one with your changed
                        terms and a summary of what changed
                        {packet.status !== 'completed'
                            ? '; this packet is voided'
                            : ''}
                        .
                    </p>
                    <FieldPicker
                        forms={forms}
                        label="Add to counter"
                        onPick={pick(setCounter)}
                    />
                    {counter.length > 0 && (
                        <>
                            <ul className="grid gap-0.5">
                                {counter.map((a) => (
                                    <li key={`${a.form}:${a.key}`}>
                                        • {a.text}
                                    </li>
                                ))}
                            </ul>
                            <Button
                                size="sm"
                                className="justify-self-start"
                                onClick={() =>
                                    router.post(
                                        `/forms/packets/${packet.ulid}/counter`,
                                        { changes: byForm(counter) },
                                    )
                                }
                            >
                                Draft counter-offer
                            </Button>
                        </>
                    )}
                </div>
            )}

            {packet.status === 'completed' && templates.length > 0 && (
                <div className="grid gap-2 border-t pt-2">
                    <h3 className="font-medium">Addendum</h3>
                    <div className="flex flex-wrap gap-3">
                        {templates.map((t) => (
                            <label
                                key={t.ulid}
                                className="flex items-center gap-1"
                            >
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={addenda.includes(t.ulid)}
                                    onChange={(e) =>
                                        setAddenda(
                                            e.target.checked
                                                ? [...addenda, t.ulid]
                                                : addenda.filter(
                                                      (x) => x !== t.ulid,
                                                  ),
                                        )
                                    }
                                />
                                {t.name}
                            </label>
                        ))}
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        className="justify-self-start"
                        disabled={addenda.length === 0}
                        onClick={() =>
                            router.post(
                                `/forms/packets/${packet.ulid}/addendum`,
                                { templates: addenda },
                            )
                        }
                    >
                        Start addendum with the same signers
                    </Button>
                </div>
            )}

            {negotiation.length > 1 && (
                <div className="grid gap-1 border-t pt-2">
                    <h3 className="font-medium">Negotiation history</h3>
                    <ol className="grid gap-1">
                        {negotiation.map((n) => (
                            <li key={n.ulid} className="grid gap-0.5">
                                <span className="flex flex-wrap items-center gap-2">
                                    {n.ulid === packet.ulid ? (
                                        <strong>{n.title}</strong>
                                    ) : (
                                        <Link
                                            className="underline"
                                            href={`/forms/packets/${n.ulid}`}
                                        >
                                            {n.title}
                                        </Link>
                                    )}
                                    <Badge
                                        variant="outline"
                                        className="capitalize"
                                    >
                                        {n.kind} · {n.status}
                                    </Badge>
                                </span>
                                {n.changes.map((c) => (
                                    <span
                                        key={c}
                                        className="pl-3 text-xs text-muted-foreground"
                                    >
                                        {c}
                                    </span>
                                ))}
                            </li>
                        ))}
                    </ol>
                </div>
            )}
        </section>
    );
}
