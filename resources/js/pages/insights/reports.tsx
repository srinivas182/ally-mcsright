import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';
import { postJson } from '@/lib/http';

type Column = {
    label: string;
    type: string;
    options: string[];
    filterable: boolean;
};
type Subject = {
    label: string;
    default: string[];
    columns: Record<string, Column>;
};
type Filter = { column: string; op: string; value: string };
type Definition = {
    subject: string;
    columns: string[];
    filters: Filter[];
    group_by: string;
};
type Result = {
    columns: { key: string; label: string; type: string }[];
    rows: Record<string, string | number | boolean | null>[];
    groups:
        | { key: string; count: number; sums: Record<string, number | null> }[]
        | null;
    total: number;
    truncated: boolean;
    hidden: string[];
};
type Saved = {
    ulid: string;
    name: string;
    subject: string;
    shared: boolean;
    schedule: string | null;
    mine: boolean;
    definition: Definition;
};
type Props = {
    catalogue: Record<string, Subject>;
    ops: Record<string, string>;
    saved: Saved[];
    open: string | null;
};

const select = 'rounded border bg-background px-2 py-1';

const show = (type: string, v: string | number | boolean | null | undefined) =>
    v === null || v === undefined || v === ''
        ? '—'
        : type === 'money'
          ? usd(Number(v))
          : String(v);

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** The report builder and "Ask your numbers" (specs/insights/01, K2). */
export default function Reports({ catalogue, ops, saved, open }: Props) {
    const first = saved.find((s) => s.ulid === open);
    const [def, setDef] = useState<Definition>(
        first?.definition ?? {
            subject: 'deals',
            columns: catalogue.deals.default,
            filters: [],
            group_by: '',
        },
    );
    const [result, setResult] = useState<Result | null>(null);
    const [name, setName] = useState(first?.name ?? '');
    const [shared, setShared] = useState(first?.shared ?? false);
    const [schedule, setSchedule] = useState(first?.schedule ?? '');
    const [current, setCurrent] = useState<string | null>(first?.ulid ?? null);
    const [question, setQuestion] = useState('');
    const [intro, setIntro] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const subject = catalogue[def.subject];

    const run = (d: Definition = def) =>
        void postJson<Result>('/insights/reports/run', d)
            .then((r) => {
                setResult(r);
                setError(null);
            })
            .catch((e: Error) => setError(e.message));

    useEffect(() => {
        if (first) {
            run(first.definition);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const ask = () => {
        setIntro('Working it out…');
        void postJson<{
            definition: Definition | null;
            intro: string;
            result: Result | null;
        }>('/insights/reports/ask', { question })
            .then((r) => {
                setIntro(r.intro);
                if (r.definition && r.result) {
                    setDef({
                        ...r.definition,
                        filters: r.definition.filters ?? [],
                    });
                    setResult(r.result);
                    setCurrent(null);
                    setName(question.slice(0, 120));
                }
            })
            .catch((e: Error) => setIntro(e.message));
    };

    const exportCsv = async () => {
        const res = await fetch('/insights/reports/export', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({ ...def, name: name || 'report' }),
        });
        const blob = await res.blob();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `${(name || 'report').replace(/[^a-z0-9]+/gi, '-').toLowerCase()}.csv`;
        a.click();
    };

    return (
        <>
            <Head title="Reports" />
            <div className="flex flex-col gap-4 p-4 text-sm md:p-6">
                <PageHeader
                    title="Reports"
                    description="Build any report on deals, contacts, leads or agents, or just ask. Everyone sees only what their role allows, even on a shared report."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/insights">Numbers</Link>
                        </Button>
                    }
                />
                <section className="grid gap-2 rounded-lg border p-4">
                    <p className="font-medium">Ask your numbers</p>
                    <span className="flex flex-wrap gap-2">
                        <Input
                            aria-label="Your question"
                            className="min-w-64 flex-1"
                            placeholder="e.g. Which agents closed the most volume this year?"
                            value={question}
                            onChange={(e) => setQuestion(e.target.value)}
                        />
                        <Button disabled={question.trim() === ''} onClick={ask}>
                            Ask
                        </Button>
                    </span>
                    {intro && <p className="text-muted-foreground">{intro}</p>}
                </section>
                {saved.length > 0 && (
                    <section className="grid gap-2">
                        <p className="font-medium">Saved reports</p>
                        <span className="flex flex-wrap gap-2">
                            {saved.map((s) => (
                                <Button
                                    key={s.ulid}
                                    size="sm"
                                    variant={
                                        current === s.ulid
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => {
                                        setDef(s.definition);
                                        setName(s.name);
                                        setShared(s.shared);
                                        setSchedule(s.schedule ?? '');
                                        setCurrent(s.mine ? s.ulid : null);
                                        run(s.definition);
                                    }}
                                >
                                    {s.name}
                                    {s.shared && !s.mine && ' (shared)'}
                                    {s.schedule && ` · ${s.schedule}`}
                                </Button>
                            ))}
                        </span>
                    </section>
                )}
                <section className="grid gap-3 rounded-lg border p-4">
                    <label className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">Report on</span>
                        <select
                            className={select}
                            value={def.subject}
                            onChange={(e) =>
                                setDef({
                                    subject: e.target.value,
                                    columns: catalogue[e.target.value].default,
                                    filters: [],
                                    group_by: '',
                                })
                            }
                        >
                            {Object.entries(catalogue).map(([k, s]) => (
                                <option key={k} value={k}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <fieldset className="flex flex-wrap gap-3">
                        <legend className="mb-1 font-medium">Columns</legend>
                        {Object.entries(subject.columns).map(([k, c]) => (
                            <label key={k} className="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={def.columns.includes(k)}
                                    onChange={(e) =>
                                        setDef({
                                            ...def,
                                            columns: e.target.checked
                                                ? [...def.columns, k]
                                                : def.columns.filter(
                                                      (x) => x !== k,
                                                  ),
                                        })
                                    }
                                />
                                {c.label}
                            </label>
                        ))}
                    </fieldset>
                    <div className="grid gap-2">
                        <span className="font-medium">Filters</span>
                        {def.filters.map((f, i) => (
                            <span
                                key={i}
                                className="flex flex-wrap items-center gap-2"
                            >
                                <select
                                    aria-label="Column"
                                    className={select}
                                    value={f.column}
                                    onChange={(e) =>
                                        setDef({
                                            ...def,
                                            filters: def.filters.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          column: e.target
                                                              .value,
                                                      }
                                                    : x,
                                            ),
                                        })
                                    }
                                >
                                    {Object.entries(subject.columns)
                                        .filter(([, c]) => c.filterable)
                                        .map(([k, c]) => (
                                            <option key={k} value={k}>
                                                {c.label}
                                            </option>
                                        ))}
                                </select>
                                <select
                                    aria-label="How"
                                    className={select}
                                    value={f.op}
                                    onChange={(e) =>
                                        setDef({
                                            ...def,
                                            filters: def.filters.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          op: e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        })
                                    }
                                >
                                    {Object.entries(ops).map(([k, l]) => (
                                        <option key={k} value={k}>
                                            {l}
                                        </option>
                                    ))}
                                </select>
                                {!['filled', 'empty'].includes(f.op) && (
                                    <Input
                                        aria-label="Value"
                                        className="w-44"
                                        type={
                                            ['from', 'to'].includes(f.op)
                                                ? 'date'
                                                : 'text'
                                        }
                                        value={f.value}
                                        onChange={(e) =>
                                            setDef({
                                                ...def,
                                                filters: def.filters.map(
                                                    (x, j) =>
                                                        j === i
                                                            ? {
                                                                  ...x,
                                                                  value: e
                                                                      .target
                                                                      .value,
                                                              }
                                                            : x,
                                                ),
                                            })
                                        }
                                    />
                                )}
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        setDef({
                                            ...def,
                                            filters: def.filters.filter(
                                                (_, j) => j !== i,
                                            ),
                                        })
                                    }
                                >
                                    Remove
                                </Button>
                            </span>
                        ))}
                        <Button
                            size="sm"
                            variant="outline"
                            className="justify-self-start"
                            onClick={() =>
                                setDef({
                                    ...def,
                                    filters: [
                                        ...def.filters,
                                        {
                                            column:
                                                Object.entries(
                                                    subject.columns,
                                                ).find(
                                                    ([, c]) => c.filterable,
                                                )?.[0] ?? '',
                                            op: 'is',
                                            value: '',
                                        },
                                    ],
                                })
                            }
                        >
                            Add a filter
                        </Button>
                    </div>
                    <label className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">Group by</span>
                        <select
                            className={select}
                            value={def.group_by}
                            onChange={(e) =>
                                setDef({ ...def, group_by: e.target.value })
                            }
                        >
                            <option value="">No grouping</option>
                            {def.columns.map((k) => (
                                <option key={k} value={k}>
                                    {subject.columns[k]?.label ?? k}
                                </option>
                            ))}
                            {Object.entries(subject.columns)
                                .filter(([, c]) => c.type === 'date')
                                .map(([k, c]) => (
                                    <option key={`m${k}`} value={`month:${k}`}>
                                        Month of {c.label.toLowerCase()}
                                    </option>
                                ))}
                        </select>
                    </label>
                    <span className="flex flex-wrap gap-2">
                        <Button onClick={() => run()}>Run</Button>
                        <Button
                            variant="outline"
                            onClick={() => void exportCsv()}
                        >
                            Export CSV
                        </Button>
                    </span>
                </section>
                {error && (
                    <p role="alert" className="text-destructive">
                        {error}
                    </p>
                )}
                {result && (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <p className="text-muted-foreground">
                            {result.total} {result.total === 1 ? 'row' : 'rows'}
                            {result.truncated &&
                                ' (showing the first 5,000; export for all of them)'}
                            {result.hidden.length > 0 &&
                                ` · hidden for your role: ${result.hidden.join(', ')}`}
                        </p>
                        {result.groups ? (
                            <table className="w-full text-left">
                                <thead className="text-muted-foreground">
                                    <tr>
                                        <th className="py-1 font-normal">
                                            Group
                                        </th>
                                        <th className="py-1 text-right font-normal">
                                            Count
                                        </th>
                                        {result.columns
                                            .filter((c) =>
                                                ['money', 'number'].includes(
                                                    c.type,
                                                ),
                                            )
                                            .map((c) => (
                                                <th
                                                    key={c.key}
                                                    className="py-1 text-right font-normal"
                                                >
                                                    {c.label}
                                                </th>
                                            ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {result.groups.map((g) => (
                                        <tr key={g.key} className="border-t">
                                            <td className="py-1">{g.key}</td>
                                            <td className="text-right">
                                                {g.count}
                                            </td>
                                            {result.columns
                                                .filter((c) =>
                                                    [
                                                        'money',
                                                        'number',
                                                    ].includes(c.type),
                                                )
                                                .map((c) => (
                                                    <td
                                                        key={c.key}
                                                        className="text-right"
                                                    >
                                                        {show(
                                                            c.type,
                                                            g.sums[c.key] ??
                                                                null,
                                                        )}
                                                    </td>
                                                ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left">
                                    <thead className="text-muted-foreground">
                                        <tr>
                                            {result.columns.map((c) => (
                                                <th
                                                    key={c.key}
                                                    className="py-1 font-normal"
                                                >
                                                    {c.label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {result.rows
                                            .slice(0, 500)
                                            .map((r, i) => (
                                                <tr
                                                    key={i}
                                                    className="border-t"
                                                >
                                                    {result.columns.map((c) => (
                                                        <td
                                                            key={c.key}
                                                            className="py-1"
                                                        >
                                                            {def.subject ===
                                                                'deals' &&
                                                            c.key ===
                                                                'street' &&
                                                            r._ulid ? (
                                                                <Link
                                                                    href={`/deals/${String(r._ulid)}`}
                                                                    className="underline-offset-2 hover:underline"
                                                                >
                                                                    {show(
                                                                        c.type,
                                                                        r[
                                                                            c
                                                                                .key
                                                                        ],
                                                                    )}
                                                                </Link>
                                                            ) : def.subject ===
                                                                  'contacts' &&
                                                              c.key ===
                                                                  'name' &&
                                                              r._ulid ? (
                                                                <Link
                                                                    href={`/contacts/${String(r._ulid)}`}
                                                                    className="underline-offset-2 hover:underline"
                                                                >
                                                                    {show(
                                                                        c.type,
                                                                        r[
                                                                            c
                                                                                .key
                                                                        ],
                                                                    )}
                                                                </Link>
                                                            ) : (
                                                                show(
                                                                    c.type,
                                                                    r[c.key],
                                                                )
                                                            )}
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <span className="flex flex-wrap items-center gap-2 border-t pt-3">
                            <Input
                                aria-label="Report name"
                                placeholder="Name this report"
                                className="w-64"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                            />
                            <label className="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={shared}
                                    onChange={(e) =>
                                        setShared(e.target.checked)
                                    }
                                />
                                Share with the brokerage
                            </label>
                            <select
                                aria-label="Email it to me"
                                className={select}
                                value={schedule}
                                onChange={(e) => setSchedule(e.target.value)}
                            >
                                <option value="">Don't email it</option>
                                <option value="weekly">
                                    Email it weekly (Mondays)
                                </option>
                                <option value="monthly">
                                    Email it monthly (the 1st)
                                </option>
                            </select>
                            <Button
                                variant="outline"
                                disabled={name.trim() === ''}
                                onClick={() => {
                                    const data = {
                                        name,
                                        definition: def,
                                        shared,
                                        schedule: schedule || null,
                                    };
                                    if (current) {
                                        router.put(
                                            `/insights/reports/${current}`,
                                            data,
                                            { preserveScroll: true },
                                        );
                                    } else {
                                        router.post('/insights/reports', data, {
                                            preserveScroll: true,
                                        });
                                    }
                                }}
                            >
                                {current ? 'Save changes' : 'Save report'}
                            </Button>
                            {current && (
                                <Button
                                    variant="ghost"
                                    onClick={() =>
                                        router.delete(
                                            `/insights/reports/${current}`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Delete
                                </Button>
                            )}
                            {shared && (
                                <Badge variant="outline">
                                    Each person sees only what their role allows
                                </Badge>
                            )}
                        </span>
                    </section>
                )}
            </div>
        </>
    );
}

Reports.layout = {
    breadcrumbs: [{ title: 'Reports', href: '/insights/reports' }],
};
