import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Field = {
    ulid: string;
    key: string;
    label: string;
    type: string;
    options: string[];
    required_to_close: boolean;
    hidden_from: string | null;
    help: string | null;
    archived: boolean;
};
type Props = {
    entities: Record<string, string>;
    types: Record<string, string>;
    groups: Record<string, string>;
    fields: Record<string, Field[]>;
};

function Editor({
    entity,
    field,
    types,
    groups,
    onDone,
}: {
    entity: string;
    field: Field | null;
    types: Props['types'];
    groups: Props['groups'];
    onDone: () => void;
}) {
    const [label, setLabel] = useState(field?.label ?? '');
    const [type, setType] = useState(field?.type ?? 'text');
    const [options, setOptions] = useState((field?.options ?? []).join('\n'));
    const [required, setRequired] = useState(field?.required_to_close ?? false);
    const [hidden, setHidden] = useState(field?.hidden_from ?? '');
    const [help, setHelp] = useState(field?.help ?? '');
    const [error, setError] = useState<string | null>(null);
    const save = () => {
        const data = {
            label,
            type,
            options: options
                .split('\n')
                .map((o) => o.trim())
                .filter(Boolean),
            required_to_close: required,
            hidden_from: hidden || null,
            help,
        };
        const opts = {
            preserveScroll: true,
            onSuccess: onDone,
            onError: (e: Record<string, string>) =>
                setError(Object.values(e)[0] ?? null),
        };
        if (field) {
            router.put(
                `/settings/custom-fields/${entity}/${field.ulid}`,
                data,
                opts,
            );
        } else {
            router.post(`/settings/custom-fields/${entity}`, data, opts);
        }
    };

    return (
        <div className="grid gap-2 rounded-md border bg-muted/30 p-3 text-sm">
            <Input
                aria-label="Field name"
                placeholder="Field name, e.g. Pre-approved with"
                value={label}
                onChange={(e) => setLabel(e.target.value)}
            />
            <select
                aria-label="Field type"
                className="rounded border bg-background px-2 py-1"
                value={type}
                onChange={(e) => setType(e.target.value)}
            >
                {Object.entries(types).map(([v, l]) => (
                    <option key={v} value={v}>
                        {l}
                    </option>
                ))}
            </select>
            {(type === 'choice' || type === 'multi') && (
                <textarea
                    aria-label="Choices, one per line"
                    placeholder="Choices, one per line"
                    className="min-h-20 rounded border bg-background p-2"
                    value={options}
                    onChange={(e) => setOptions(e.target.value)}
                />
            )}
            {entity === 'deal' && (
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={required}
                        onChange={(e) => setRequired(e.target.checked)}
                    />
                    Needed before the deal can close
                </label>
            )}
            <label className="flex flex-wrap items-center gap-2">
                Hide from roles without:
                <select
                    aria-label="Hide from roles without"
                    className="rounded border bg-background px-2 py-1"
                    value={hidden}
                    onChange={(e) => setHidden(e.target.value)}
                >
                    <option value="">
                        Nobody (everyone who sees the record)
                    </option>
                    {Object.entries(groups).map(([v, l]) => (
                        <option key={v} value={v}>
                            {l}
                        </option>
                    ))}
                </select>
            </label>
            <Input
                aria-label="Help text (optional)"
                placeholder="Help text (optional)"
                value={help}
                onChange={(e) => setHelp(e.target.value)}
            />
            {error && (
                <p role="alert" className="text-destructive">
                    {error}
                </p>
            )}
            <span className="flex gap-2">
                <Button size="sm" onClick={save}>
                    Save field
                </Button>
                <Button size="sm" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
            </span>
        </div>
    );
}

/** Settings → Custom fields (specs/core/10, N1). */
export default function CustomFieldsSettings({
    entities,
    types,
    groups,
    fields,
}: Props) {
    const [editing, setEditing] = useState<{
        entity: string;
        field: Field | null;
    } | null>(null);

    return (
        <>
            <Head title="Custom fields" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Custom fields"
                    description="Add the details your brokerage tracks to contacts, deals and agents. They show on each record, work in smart lists and imports, and can be hidden from roles."
                />
                {Object.entries(entities).map(([entity, name]) => (
                    <section
                        key={entity}
                        className="grid gap-2 rounded-lg border p-4 text-sm"
                    >
                        <div className="flex items-center justify-between">
                            <h2 className="font-medium">{name}</h2>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setEditing({ entity, field: null })
                                }
                            >
                                Add a field
                            </Button>
                        </div>
                        {editing?.entity === entity &&
                            editing.field === null && (
                                <Editor
                                    entity={entity}
                                    field={null}
                                    types={types}
                                    groups={groups}
                                    onDone={() => setEditing(null)}
                                />
                            )}
                        {(fields[entity] ?? []).length === 0 && (
                            <p className="text-muted-foreground">
                                No custom fields yet.
                            </p>
                        )}
                        <ul className="grid gap-2">
                            {(fields[entity] ?? []).map((f) => (
                                <li key={f.ulid} className="grid gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span
                                            className={
                                                f.archived
                                                    ? 'text-muted-foreground line-through'
                                                    : 'font-medium'
                                            }
                                        >
                                            {f.label}
                                        </span>
                                        <Badge variant="outline">
                                            {types[f.type] ?? f.type}
                                        </Badge>
                                        {f.required_to_close && (
                                            <Badge variant="secondary">
                                                Needed to close
                                            </Badge>
                                        )}
                                        {f.hidden_from && (
                                            <Badge variant="secondary">
                                                Hidden without{' '}
                                                {groups[f.hidden_from]}
                                            </Badge>
                                        )}
                                        <span className="ml-auto flex gap-2">
                                            {!f.archived && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setEditing({
                                                            entity,
                                                            field: f,
                                                        })
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                            )}
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.post(
                                                        `/settings/custom-fields/${entity}/${f.ulid}/archive`,
                                                        f.archived
                                                            ? { restore: true }
                                                            : {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {f.archived
                                                    ? 'Restore'
                                                    : 'Archive'}
                                            </Button>
                                        </span>
                                    </div>
                                    {editing?.field?.ulid === f.ulid && (
                                        <Editor
                                            entity={entity}
                                            field={f}
                                            types={types}
                                            groups={groups}
                                            onDone={() => setEditing(null)}
                                        />
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </>
    );
}

CustomFieldsSettings.layout = {
    breadcrumbs: [{ title: 'Custom fields', href: '/settings/custom-fields' }],
};
