import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { FieldCanvas } from '@/components/forms/field-canvas';
import {
    IdentitySettings,
    type IdentitySettingsData,
} from '@/components/forms/identity-settings';

type Field = {
    key: string;
    type: string;
    page: number;
    x: number;
    y: number;
    w: number;
    h: number;
    role: string | null;
    bind: string | null;
    required: boolean;
    label: string;
};
type Template = {
    ulid: string;
    name: string;
    pages: number;
    version: number;
    fields: Field[];
    page_sizes: [number, number][];
    checklist_label: string | null;
    high_risk: boolean;
    edition: string | null;
    import_source: string | null;
};
type Props = {
    templates: Template[];
    clauses: { ulid: string; title: string; body: string }[];
    bindings: string[];
    types: string[];
    identity: IdentitySettingsData;
    clause_review: string;
};

const blank: Field = {
    key: '',
    type: 'text',
    page: 1,
    x: 72,
    y: 72,
    w: 200,
    h: 14,
    role: null,
    bind: null,
    required: false,
    label: '',
};
const cell = 'rounded border bg-background px-1 py-1 text-xs';

function FieldEditor({
    t,
    bindings,
    types,
}: {
    t: Template;
    bindings: string[];
    types: string[];
}) {
    const [fields, setFields] = useState<Field[]>(t.fields);
    const [mode, setMode] = useState<'visual' | 'table'>('visual');
    const set = (i: number, patch: Partial<Field>) =>
        setFields(fields.map((f, j) => (j === i ? { ...f, ...patch } : f)));

    return (
        <details className="rounded-lg border p-3 text-sm">
            <summary className="cursor-pointer font-medium">
                {t.name}
                {t.edition ? ` (edition ${t.edition})` : ''} · {t.pages} page(s)
                · version {t.version} · {t.fields.length} field(s)
            </summary>
            <div
                role="group"
                aria-label="Editor"
                className="my-2 flex w-fit rounded border text-xs"
            >
                <button
                    type="button"
                    aria-pressed={mode === 'visual'}
                    className={`px-3 py-1 ${mode === 'visual' ? 'bg-muted font-medium' : ''}`}
                    onClick={() => setMode('visual')}
                >
                    On the page
                </button>
                <button
                    type="button"
                    aria-pressed={mode === 'table'}
                    className={`px-3 py-1 ${mode === 'table' ? 'bg-muted font-medium' : ''}`}
                    onClick={() => setMode('table')}
                >
                    As a table
                </button>
            </div>
            {mode === 'visual' && (
                <FieldCanvas
                    ulid={t.ulid}
                    pages={t.pages}
                    pageSizes={t.page_sizes}
                    fields={fields}
                    setFields={setFields}
                    bindings={bindings}
                    types={types}
                    onImageUnavailable={() => setMode('table')}
                />
            )}
            {mode === 'table' && (
                <div className="overflow-x-auto">
                    <table className="w-full text-xs">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                {[
                                    'Key',
                                    'Label',
                                    'Type',
                                    'Page',
                                    'X',
                                    'Y',
                                    'W',
                                    'H',
                                    'Signer',
                                    'Fill from',
                                    'Req.',
                                    '',
                                ].map((h) => (
                                    <th key={h} className="p-1">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {fields.map((f, i) => (
                                <tr key={i}>
                                    <td className="p-1">
                                        <input
                                            aria-label="Key"
                                            className={`${cell} w-28`}
                                            value={f.key}
                                            onChange={(e) =>
                                                set(i, { key: e.target.value })
                                            }
                                        />
                                    </td>
                                    <td className="p-1">
                                        <input
                                            aria-label="Label"
                                            className={`${cell} w-32`}
                                            value={f.label}
                                            onChange={(e) =>
                                                set(i, {
                                                    label: e.target.value,
                                                })
                                            }
                                        />
                                    </td>
                                    <td className="p-1">
                                        <select
                                            aria-label="Type"
                                            className={cell}
                                            value={f.type}
                                            onChange={(e) =>
                                                set(i, { type: e.target.value })
                                            }
                                        >
                                            {types.map((x) => (
                                                <option key={x}>{x}</option>
                                            ))}
                                        </select>
                                    </td>
                                    {(
                                        ['page', 'x', 'y', 'w', 'h'] as const
                                    ).map((k) => (
                                        <td key={k} className="p-1">
                                            <input
                                                aria-label={k}
                                                type="number"
                                                className={`${cell} w-14`}
                                                value={f[k]}
                                                onChange={(e) =>
                                                    set(i, {
                                                        [k]: Number(
                                                            e.target.value,
                                                        ),
                                                    })
                                                }
                                            />
                                        </td>
                                    ))}
                                    <td className="p-1">
                                        <input
                                            aria-label="Signer role"
                                            className={`${cell} w-24`}
                                            placeholder="buyer1"
                                            value={f.role ?? ''}
                                            onChange={(e) =>
                                                set(i, {
                                                    role:
                                                        e.target.value || null,
                                                })
                                            }
                                        />
                                    </td>
                                    <td className="p-1">
                                        <select
                                            aria-label="Fill from"
                                            className={cell}
                                            value={f.bind ?? ''}
                                            onChange={(e) =>
                                                set(i, {
                                                    bind:
                                                        e.target.value || null,
                                                })
                                            }
                                        >
                                            <option value="">—</option>
                                            {bindings.map((b) => (
                                                <option key={b}>{b}</option>
                                            ))}
                                        </select>
                                    </td>
                                    <td className="p-1">
                                        <input
                                            aria-label="Required"
                                            type="checkbox"
                                            checked={f.required}
                                            onChange={(e) =>
                                                set(i, {
                                                    required: e.target.checked,
                                                })
                                            }
                                        />
                                    </td>
                                    <td className="p-1">
                                        <button
                                            type="button"
                                            className="underline"
                                            onClick={() =>
                                                setFields(
                                                    fields.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <span className="mt-2 flex gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setFields([...fields, { ...blank }])}
                >
                    Add field
                </Button>
                <Button
                    size="sm"
                    onClick={() =>
                        router.post(
                            `/forms/templates/${t.ulid}/fields`,
                            { fields },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save fields
                </Button>
            </span>
        </details>
    );
}

/** Settings › Forms: the brokerage's forms, their fields, and the clause library. */
export default function FormTemplates({
    templates,
    clauses,
    bindings,
    types,
    identity,
    clause_review,
}: Props) {
    const upload = useForm<{
        name: string;
        checklist_label: string;
        file: File | null;
    }>({ name: '', checklist_label: '', file: null });
    const clause = useForm({ title: '', body: '' });

    return (
        <>
            <Head title="Forms" />
            <div className="flex flex-col gap-4">
                <PageHeader
                    title="Forms"
                    description="Your brokerage's forms, filled from each deal."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/forms/libraries">
                                Association libraries and imports
                            </Link>
                        </Button>
                    }
                />
                <form
                    className="flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        upload.post('/forms/templates', {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () => upload.reset(),
                        });
                    }}
                >
                    <Input
                        aria-label="Form name"
                        placeholder="Form name"
                        className="w-56"
                        value={upload.data.name}
                        onChange={(e) => upload.setData('name', e.target.value)}
                    />
                    <Input
                        aria-label="File to checklist item"
                        placeholder="Checklist item to file it to (optional)"
                        className="w-64"
                        value={upload.data.checklist_label}
                        onChange={(e) =>
                            upload.setData('checklist_label', e.target.value)
                        }
                    />
                    <input
                        aria-label="PDF"
                        type="file"
                        accept="application/pdf"
                        onChange={(e) =>
                            upload.setData('file', e.target.files?.[0] ?? null)
                        }
                    />
                    <Button
                        type="submit"
                        size="sm"
                        disabled={!upload.data.file || !upload.data.name}
                    >
                        Upload form
                    </Button>
                    {Object.values(upload.errors)[0] && (
                        <p role="alert" className="w-full text-destructive">
                            {Object.values(upload.errors)[0]}
                        </p>
                    )}
                </form>
                {templates.map((t) => (
                    <FieldEditor
                        key={t.ulid}
                        t={t}
                        bindings={bindings}
                        types={types}
                    />
                ))}
                <IdentitySettings settings={identity} templates={templates} />
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">Clause library</h2>
                    <label className="flex flex-wrap items-center gap-2">
                        Special terms not from this library:
                        <select
                            aria-label="Clause check"
                            className="rounded border bg-background px-2 py-1.5"
                            value={clause_review}
                            onChange={(e) =>
                                router.post(
                                    '/forms/clause-setting',
                                    { clause_review: e.target.value },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <option value="warn">Warn the agent</option>
                            <option value="require">
                                Need broker approval before sending
                            </option>
                            <option value="off">Don't check</option>
                        </select>
                    </label>
                    {clauses.map((c) => (
                        <details key={c.ulid}>
                            <summary className="cursor-pointer">
                                {c.title}
                            </summary>
                            <p className="whitespace-pre-wrap text-muted-foreground">
                                {c.body}
                            </p>
                        </details>
                    ))}
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            clause.post('/forms/clauses', {
                                preserveScroll: true,
                                onSuccess: () => clause.reset(),
                            });
                        }}
                    >
                        <Input
                            aria-label="Clause title"
                            placeholder="Clause title"
                            value={clause.data.title}
                            onChange={(e) =>
                                clause.setData('title', e.target.value)
                            }
                        />
                        <textarea
                            aria-label="Clause wording"
                            className="min-h-20 rounded border bg-background p-2"
                            value={clause.data.body}
                            onChange={(e) =>
                                clause.setData('body', e.target.value)
                            }
                        />
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            className="justify-self-start"
                        >
                            Add clause
                        </Button>
                        {Object.values(clause.errors)[0] && (
                            <p role="alert" className="text-destructive">
                                {Object.values(clause.errors)[0]}
                            </p>
                        )}
                    </form>
                </section>
            </div>
        </>
    );
}

FormTemplates.layout = {
    breadcrumbs: [{ title: 'Forms', href: '/forms/templates' }],
};
