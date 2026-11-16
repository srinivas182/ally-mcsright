import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { postJson } from '@/lib/http';

export type CustomFieldValue = {
    key: string;
    label: string;
    type: string;
    options: string[];
    required_to_close: boolean;
    help: string | null;
    value: string | string[] | boolean | null;
    hidden: boolean;
};
type Draft = Record<string, string | string[] | boolean | null>;

const show = (f: CustomFieldValue): string => {
    if (f.hidden) {
        return 'Hidden for your role';
    }
    if (f.value === null || f.value === '') {
        return '—';
    }
    if (typeof f.value === 'boolean') {
        return f.value ? 'Yes' : 'No';
    }
    if (Array.isArray(f.value)) {
        return f.value.join(', ');
    }
    return f.type === 'money'
        ? `$${Number(f.value).toLocaleString()}`
        : f.value;
};

function FieldInput({
    field,
    value,
    onChange,
}: {
    field: CustomFieldValue;
    value: Draft[string];
    onChange: (v: Draft[string]) => void;
}) {
    if (field.type === 'yesno') {
        return (
            <select
                aria-label={field.label}
                className="rounded border bg-background px-2 py-1"
                value={value === true ? 'yes' : value === false ? 'no' : ''}
                onChange={(e) =>
                    onChange(
                        e.target.value === '' ? null : e.target.value === 'yes',
                    )
                }
            >
                <option value="">—</option>
                <option value="yes">Yes</option>
                <option value="no">No</option>
            </select>
        );
    }
    if (field.type === 'choice') {
        return (
            <select
                aria-label={field.label}
                className="rounded border bg-background px-2 py-1"
                value={typeof value === 'string' ? value : ''}
                onChange={(e) => onChange(e.target.value || null)}
            >
                <option value="">—</option>
                {field.options.map((o) => (
                    <option key={o}>{o}</option>
                ))}
            </select>
        );
    }
    if (field.type === 'multi') {
        const picked = Array.isArray(value) ? value : [];
        return (
            <span className="flex flex-wrap gap-3">
                {field.options.map((o) => (
                    <label key={o} className="flex items-center gap-1">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={picked.includes(o)}
                            onChange={(e) =>
                                onChange(
                                    e.target.checked
                                        ? [...picked, o]
                                        : picked.filter((p) => p !== o),
                                )
                            }
                        />
                        {o}
                    </label>
                ))}
            </span>
        );
    }
    return (
        <Input
            aria-label={field.label}
            type={
                field.type === 'date'
                    ? 'date'
                    : field.type === 'number' || field.type === 'money'
                      ? 'number'
                      : field.type === 'url'
                        ? 'url'
                        : 'text'
            }
            step={field.type === 'money' ? '0.01' : undefined}
            value={typeof value === 'string' ? value : ''}
            onChange={(e) => onChange(e.target.value)}
        />
    );
}

/**
 * Custom fields on a contact, deal or agent (specs/core/10, N1), with "Fill with
 * AI" on contacts: a suggestion from the notes that the person confirms.
 */
export function CustomFieldsPanel({
    fields,
    action,
    canEdit,
    suggestUrl,
}: {
    fields: CustomFieldValue[];
    action: string;
    canEdit: boolean;
    suggestUrl?: string;
}) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState<Draft>({});
    const [error, setError] = useState<string | null>(null);
    const [hint, setHint] = useState<Record<string, string>>({});

    if (fields.length === 0) {
        return null;
    }
    const begin = () => {
        setDraft(
            Object.fromEntries(
                fields.filter((f) => !f.hidden).map((f) => [f.key, f.value]),
            ),
        );
        setEditing(true);
    };
    const suggest = (f: CustomFieldValue) => {
        if (!suggestUrl) {
            return;
        }
        setHint({ ...hint, [f.key]: 'Reading the notes…' });
        void postJson<{ value: string | null; evidence: string | null }>(
            suggestUrl,
            { key: f.key },
        )
            .then((r) => {
                if (r.value !== null) {
                    setDraft((d) => ({
                        ...d,
                        [f.key]:
                            f.type === 'yesno'
                                ? r.value === 'yes'
                                : f.type === 'multi'
                                  ? (r.value ?? '').split(', ')
                                  : r.value,
                    }));
                }
                setHint((h) => ({
                    ...h,
                    [f.key]:
                        r.value === null
                            ? (r.evidence ?? "The notes don't say.")
                            : `Suggested from: "${r.evidence ?? 'the notes'}". Check it, then save.`,
                }));
            })
            .catch((e: Error) =>
                setHint((h) => ({ ...h, [f.key]: e.message })),
            );
    };

    return (
        <section className="grid gap-2 rounded-lg border p-4 text-sm">
            <div className="flex items-center justify-between">
                <p className="font-medium">More details</p>
                {canEdit && !editing && (
                    <Button size="sm" variant="outline" onClick={begin}>
                        Edit
                    </Button>
                )}
            </div>
            <dl className="grid gap-2 md:grid-cols-2">
                {fields.map((f) => (
                    <div key={f.key} className="grid gap-1">
                        <dt className="text-muted-foreground">
                            {f.label}
                            {f.required_to_close && ' (needed to close)'}
                        </dt>
                        <dd>
                            {editing && !f.hidden ? (
                                <span className="grid gap-1">
                                    <FieldInput
                                        field={f}
                                        value={draft[f.key] ?? null}
                                        onChange={(v) =>
                                            setDraft({ ...draft, [f.key]: v })
                                        }
                                    />
                                    {suggestUrl && (
                                        <Button
                                            size="sm"
                                            variant="link"
                                            className="h-auto justify-start p-0"
                                            onClick={() => suggest(f)}
                                        >
                                            Fill with AI
                                        </Button>
                                    )}
                                    {hint[f.key] && (
                                        <span className="text-xs text-muted-foreground">
                                            {hint[f.key]}
                                        </span>
                                    )}
                                    {f.help && (
                                        <span className="text-xs text-muted-foreground">
                                            {f.help}
                                        </span>
                                    )}
                                </span>
                            ) : (
                                show(f)
                            )}
                        </dd>
                    </div>
                ))}
            </dl>
            {error && (
                <p role="alert" className="text-destructive">
                    {error}
                </p>
            )}
            {editing && (
                <span className="flex gap-2">
                    <Button
                        size="sm"
                        onClick={() =>
                            router.post(
                                action,
                                { fields: draft },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setEditing(false);
                                        setError(null);
                                    },
                                    onError: (e) =>
                                        setError(Object.values(e)[0] ?? null),
                                },
                            )
                        }
                    >
                        Save
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setEditing(false)}
                    >
                        Cancel
                    </Button>
                </span>
            )}
        </section>
    );
}
