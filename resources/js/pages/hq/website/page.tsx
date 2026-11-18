import { Head, Link, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Block = Record<string, unknown> & { type: string };

type Props = {
    page: {
        ulid: string;
        locale: string;
        slug: string;
        title: string;
        description: string;
        status: string;
        has_draft: boolean;
        preview: string | null;
        url: string;
    } | null;
    blocks: Block[];
    types: Record<string, string>;
    checks: string[];
    versions: { id: number; title: string; at: string }[];
    locales: Record<string, string>;
};

type Field = { key: string; label: string; long?: boolean };

/** The fields each block type has; list blocks also have items with their own fields. */
const FIELDS: Record<string, { fields: Field[]; items?: Field[] }> = {
    hero: {
        fields: [
            { key: 'heading', label: 'Main heading' },
            { key: 'text', label: 'Text under it', long: true },
            { key: 'cta_label', label: 'Button text' },
            {
                key: 'cta_href',
                label: 'Button link (/start, /pricing or https://…)',
            },
            { key: 'second_label', label: 'Second button text' },
            { key: 'second_href', label: 'Second button link' },
        ],
    },
    answer: {
        fields: [
            { key: 'question', label: 'Question people ask' },
            {
                key: 'answer',
                label: 'Direct answer (25 to 80 words; answer in the first sentence)',
                long: true,
            },
        ],
    },
    features: {
        fields: [{ key: 'heading', label: 'Heading' }],
        items: [
            { key: 'title', label: 'Title' },
            { key: 'text', label: 'Text', long: true },
        ],
    },
    steps: {
        fields: [{ key: 'heading', label: 'Heading' }],
        items: [
            { key: 'title', label: 'Step' },
            { key: 'text', label: 'Text', long: true },
        ],
    },
    faq: {
        fields: [{ key: 'heading', label: 'Heading (optional)' }],
        items: [
            { key: 'q', label: 'Question' },
            { key: 'a', label: 'Answer', long: true },
        ],
    },
    pricing: {
        fields: [
            { key: 'heading', label: 'Heading (optional)' },
            { key: 'note', label: 'Note under the prices' },
        ],
    },
    text: {
        fields: [
            { key: 'heading', label: 'Heading' },
            {
                key: 'body',
                label: 'Text (blank lines start new paragraphs)',
                long: true,
            },
        ],
    },
    testimonials: {
        fields: [{ key: 'heading', label: 'Heading' }],
        items: [
            { key: 'quote', label: 'Their words', long: true },
            { key: 'name', label: 'Name' },
            { key: 'role', label: 'Brokerage or role' },
        ],
    },
    cta: {
        fields: [
            { key: 'heading', label: 'Heading' },
            { key: 'text', label: 'Text', long: true },
            { key: 'label', label: 'Button text' },
            { key: 'href', label: 'Button link' },
        ],
    },
    compare: {
        fields: [
            { key: 'heading', label: 'Heading' },
            { key: 'checked_on', label: 'Facts checked on (date)' },
            { key: 'note', label: 'Where the facts come from', long: true },
        ],
    },
};

function blank(type: string): Block {
    const b: Block = { type };
    for (const f of FIELDS[type]?.fields ?? []) {
        b[f.key] = '';
    }
    if (FIELDS[type]?.items) {
        b.items = [
            Object.fromEntries(
                FIELDS[type].items!.map((f) => [f.key, '']),
            ) as Record<string, string>,
        ];
    }
    if (type === 'compare') {
        b.columns = ['RightAlly', ''];
        b.rows = [{ label: '', values: ['', ''] }];
    }
    return b;
}

/** SG1: edit one page of RightAlly's website: draft, preview, publish, versions, and the search and AI-answer check. */
export default function HqWebsitePage({
    page,
    blocks,
    types,
    checks,
    versions,
    locales,
}: Props) {
    const form = useForm({
        locale: page?.locale ?? 'en',
        slug: page?.slug ?? '',
        title: page?.title ?? '',
        description: page?.description ?? '',
        blocks: blocks,
    });
    const opts = { preserveScroll: true };
    const set = (i: number, b: Block) =>
        form.setData(
            'blocks',
            form.data.blocks.map((x, j) => (j === i ? b : x)),
        );
    const move = (i: number, d: number) => {
        const next = [...form.data.blocks];
        const [b] = next.splice(i, 1);
        next.splice(Math.max(0, Math.min(next.length, i + d)), 0, b);
        form.setData('blocks', next);
    };

    return (
        <>
            <Head title={page ? `Edit: ${page.title}` : 'New page'} />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 text-base md:p-6">
                <p>
                    <Link href="/hq/website" className="underline">
                        ← RightAlly website
                    </Link>
                </p>
                <PageHeader
                    title={page ? page.title : 'New page'}
                    description={
                        page
                            ? `${page.url} · ${page.status === 'live' ? 'Live' : 'Draft'}${page.has_draft ? ' · changes not published yet' : ''}`
                            : 'Saved as a private draft first.'
                    }
                />

                {page && (
                    <div className="flex flex-wrap gap-2">
                        {page.preview && (
                            <Button asChild variant="outline">
                                <a
                                    href={page.preview}
                                    target="_blank"
                                    rel="noopener"
                                >
                                    Preview the draft
                                </a>
                            </Button>
                        )}
                        <Button
                            disabled={!page.has_draft}
                            onClick={() =>
                                router.post(
                                    `/hq/website/pages/${page.ulid}/publish`,
                                    {},
                                    opts,
                                )
                            }
                        >
                            Publish
                        </Button>
                        {page.status === 'live' && page.slug !== '' && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/hq/website/pages/${page.ulid}/unpublish`,
                                        {},
                                        opts,
                                    )
                                }
                            >
                                Take off the website
                            </Button>
                        )}
                    </div>
                )}

                {page && (
                    <section
                        aria-label="Search and AI-answer check"
                        className="rounded-lg border p-4"
                    >
                        <h2 className="font-semibold">
                            Search and AI-answer check
                        </h2>
                        {checks.length === 0 ? (
                            <p className="text-green-700 dark:text-green-400">
                                ✓ Ready: nothing to fix.
                            </p>
                        ) : (
                            <ul className="list-disc pl-6">
                                {checks.map((c) => (
                                    <li key={c}>{c}</li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (page) {
                            form.put(`/hq/website/pages/${page.ulid}`, opts);
                        } else {
                            form.post('/hq/website/pages', opts);
                        }
                    }}
                >
                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="grid gap-1">
                            Language
                            <select
                                className="h-10 rounded-md border bg-background px-3"
                                value={form.data.locale}
                                onChange={(e) =>
                                    form.setData('locale', e.target.value)
                                }
                            >
                                {Object.entries(locales).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1">
                            Address (empty for the home page)
                            <Input
                                value={form.data.slug}
                                placeholder="for-agents"
                                onChange={(e) =>
                                    form.setData('slug', e.target.value)
                                }
                            />
                        </label>
                    </div>
                    <label className="grid gap-1">
                        Title in search results ({form.data.title.length}/60)
                        <Input
                            value={form.data.title}
                            maxLength={70}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        Description in search results (
                        {form.data.description.length}/160)
                        <Textarea
                            value={form.data.description}
                            maxLength={170}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                    </label>

                    {form.data.blocks.map((b, i) => (
                        <fieldset
                            key={i}
                            className="grid gap-3 rounded-lg border p-4"
                        >
                            <legend className="px-1 font-semibold">
                                {i + 1}. {types[b.type] ?? b.type}
                            </legend>
                            {(FIELDS[b.type]?.fields ?? []).map((f) => (
                                <BlockField
                                    key={f.key}
                                    field={f}
                                    value={String(b[f.key] ?? '')}
                                    onChange={(v) =>
                                        set(i, { ...b, [f.key]: v })
                                    }
                                />
                            ))}
                            {b.type === 'compare' && (
                                <CompareEditor
                                    block={b}
                                    onChange={(nb) => set(i, nb)}
                                />
                            )}
                            {FIELDS[b.type]?.items && (
                                <ItemsEditor
                                    block={b}
                                    fields={FIELDS[b.type].items!}
                                    onChange={(nb) => set(i, nb)}
                                />
                            )}
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={i === 0}
                                    onClick={() => move(i, -1)}
                                >
                                    Move up
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={i === form.data.blocks.length - 1}
                                    onClick={() => move(i, 1)}
                                >
                                    Move down
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        form.setData(
                                            'blocks',
                                            form.data.blocks.filter(
                                                (_, j) => j !== i,
                                            ),
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            </div>
                        </fieldset>
                    ))}

                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            aria-label="Add a block"
                            className="h-10 rounded-md border bg-background px-3"
                            value=""
                            onChange={(e) =>
                                e.target.value &&
                                form.setData('blocks', [
                                    ...form.data.blocks,
                                    blank(e.target.value),
                                ])
                            }
                        >
                            <option value="">Add a block…</option>
                            {Object.entries(types).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                        <Button disabled={form.processing}>Save draft</Button>
                    </div>
                    {Object.values(form.errors)[0] && (
                        <p role="alert" className="text-destructive">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                </form>

                {versions.length > 0 && page && (
                    <section className="grid gap-2">
                        <h2 className="font-semibold">Published versions</h2>
                        <ul className="divide-y rounded-lg border">
                            {versions.map((v) => (
                                <li
                                    key={v.id}
                                    className="flex flex-wrap items-center justify-between gap-2 p-3"
                                >
                                    <span>
                                        {v.title} ·{' '}
                                        {new Date(v.at).toLocaleString()}
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/hq/website/pages/${page.ulid}/restore/${v.id}`,
                                                {},
                                                opts,
                                            )
                                        }
                                    >
                                        Put back in the draft
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

function BlockField({
    field,
    value,
    onChange,
}: {
    field: Field;
    value: string;
    onChange: (v: string) => void;
}) {
    return (
        <label className="grid gap-1">
            {field.label}
            {field.long ? (
                <Textarea
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
            ) : (
                <Input
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
            )}
        </label>
    );
}

function ItemsEditor({
    block,
    fields,
    onChange,
}: {
    block: Block;
    fields: Field[];
    onChange: (b: Block) => void;
}) {
    const items = (block.items as Record<string, unknown>[] | undefined) ?? [];
    const testimonials = block.type === 'testimonials';

    return (
        <div className="grid gap-3">
            {items.map((item, j) => (
                <div key={j} className="grid gap-2 rounded-md border p-3">
                    {fields.map((f) => (
                        <BlockField
                            key={f.key}
                            field={f}
                            value={String(item[f.key] ?? '')}
                            onChange={(v) =>
                                onChange({
                                    ...block,
                                    items: items.map((x, k) =>
                                        k === j ? { ...x, [f.key]: v } : x,
                                    ),
                                })
                            }
                        />
                    ))}
                    {testimonials && (
                        <label className="flex items-start gap-2">
                            <input
                                type="checkbox"
                                className="mt-1 size-5"
                                checked={item.permission === true}
                                onChange={(e) =>
                                    onChange({
                                        ...block,
                                        items: items.map((x, k) =>
                                            k === j
                                                ? {
                                                      ...x,
                                                      permission:
                                                          e.target.checked,
                                                  }
                                                : x,
                                        ),
                                    })
                                }
                            />
                            This customer agreed to their own words being shown
                            here.
                        </label>
                    )}
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="justify-self-start"
                        onClick={() =>
                            onChange({
                                ...block,
                                items: items.filter((_, k) => k !== j),
                            })
                        }
                    >
                        Remove item
                    </Button>
                </div>
            ))}
            <Button
                type="button"
                size="sm"
                variant="outline"
                className="justify-self-start"
                onClick={() =>
                    onChange({
                        ...block,
                        items: [
                            ...items,
                            Object.fromEntries(fields.map((f) => [f.key, ''])),
                        ],
                    })
                }
            >
                Add item
            </Button>
        </div>
    );
}

function CompareEditor({
    block,
    onChange,
}: {
    block: Block;
    onChange: (b: Block) => void;
}) {
    const columns = (block.columns as string[] | undefined) ?? [];
    const rows =
        (block.rows as { label: string; values: string[] }[] | undefined) ?? [];

    return (
        <div className="grid gap-2">
            <p className="text-sm text-muted-foreground">
                Columns (2 to 5). Only state facts you can show a source for.
            </p>
            <div className="flex flex-wrap gap-2">
                {columns.map((c, j) => (
                    <Input
                        key={j}
                        aria-label={`Column ${j + 1}`}
                        className="w-40"
                        value={c}
                        onChange={(e) =>
                            onChange({
                                ...block,
                                columns: columns.map((x, k) =>
                                    k === j ? e.target.value : x,
                                ),
                            })
                        }
                    />
                ))}
                {columns.length < 5 && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            onChange({
                                ...block,
                                columns: [...columns, ''],
                                rows: rows.map((r) => ({
                                    ...r,
                                    values: [...r.values, ''],
                                })),
                            })
                        }
                    >
                        Add column
                    </Button>
                )}
            </div>
            {rows.map((r, j) => (
                <div key={j} className="flex flex-wrap gap-2">
                    <Input
                        aria-label={`Row ${j + 1}`}
                        className="w-56"
                        placeholder="What is compared"
                        value={r.label}
                        onChange={(e) =>
                            onChange({
                                ...block,
                                rows: rows.map((x, k) =>
                                    k === j
                                        ? { ...x, label: e.target.value }
                                        : x,
                                ),
                            })
                        }
                    />
                    {columns.map((_, c) => (
                        <Input
                            key={c}
                            aria-label={`Row ${j + 1}, ${columns[c] || `column ${c + 1}`}`}
                            className="w-40"
                            value={r.values[c] ?? ''}
                            onChange={(e) =>
                                onChange({
                                    ...block,
                                    rows: rows.map((x, k) =>
                                        k === j
                                            ? {
                                                  ...x,
                                                  values: columns.map(
                                                      (__, cc) =>
                                                          cc === c
                                                              ? e.target.value
                                                              : (x.values[cc] ??
                                                                ''),
                                                  ),
                                              }
                                            : x,
                                    ),
                                })
                            }
                        />
                    ))}
                </div>
            ))}
            <Button
                type="button"
                size="sm"
                variant="outline"
                className="justify-self-start"
                onClick={() =>
                    onChange({
                        ...block,
                        rows: [
                            ...rows,
                            { label: '', values: columns.map(() => '') },
                        ],
                    })
                }
            >
                Add row
            </Button>
        </div>
    );
}
