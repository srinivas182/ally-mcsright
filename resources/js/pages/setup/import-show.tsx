import { Head, Link, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Preview = {
    ready: number;
    existing: number;
    problems: { row: number; why: string }[];
    problem_count: number;
    missing: string[];
    examples: Record<string, string>[];
};

type Props = {
    import: {
        ulid: string;
        filename: string;
        label: string;
        kind: 'people' | 'agents' | 'deals';
        source: string | null;
        rows: number;
        status: string;
        ai_matched: boolean;
        headers: string[];
        mapping: Record<string, string>;
        summary: Record<string, number | boolean> | null;
        crm_import: string | null;
        can_undo: boolean;
    };
    preview: Preview | null;
    kinds: Record<string, string>;
    fields: Record<string, string[]>;
};

const WHAT: Record<
    Props['import']['kind'],
    { ready: string; existing: string; go: string }
> = {
    people: {
        ready: 'people will be added',
        existing: '',
        go: 'Bring in my people',
    },
    agents: {
        ready: 'agents will get an invitation by email',
        existing: 'are already here or invited',
        go: 'Invite my agents',
    },
    deals: {
        ready: 'deals will be added under their agent',
        existing: 'are already here',
        go: 'Bring in my deals',
    },
};

const words = (field: string) => field.replaceAll('_', ' ');

/** SU1: what a file is, which column is which, and what will happen, before anything is saved. */
export default function SetupImportShow({
    import: im,
    preview,
    kinds,
    fields,
}: Props) {
    const form = useForm({
        kind: im.kind,
        mapping: im.mapping as Record<string, string | null>,
    });
    const what = WHAT[im.kind];
    const reviewing = im.status === 'reviewing';

    return (
        <>
            <Head title={im.filename} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={im.filename}
                    description={`This looks like: ${im.label}. ${im.rows} rows.${im.ai_matched ? ' Some columns were matched by AI from their names only; please check them.' : ''}`}
                    actions={
                        <Button asChild variant="outline">
                            <Link href="/setup/import">All files</Link>
                        </Button>
                    }
                />

                {reviewing && preview && (
                    <section
                        aria-labelledby="what"
                        className="grid gap-3 rounded-lg border p-4"
                    >
                        <h2 id="what" className="text-xl font-semibold">
                            What will happen
                        </h2>
                        {preview.missing.length > 0 ? (
                            <p className="text-base text-destructive">
                                Match a column for: {preview.missing.join('; ')}
                                .
                            </p>
                        ) : (
                            <ul className="grid gap-1 text-base">
                                <li>
                                    <strong>{preview.ready}</strong>{' '}
                                    {what.ready}.
                                </li>
                                {preview.existing > 0 && what.existing && (
                                    <li>
                                        <strong>{preview.existing}</strong>{' '}
                                        {what.existing}, so they are left as
                                        they are.
                                    </li>
                                )}
                                {preview.problem_count > 0 && (
                                    <li>
                                        <strong>{preview.problem_count}</strong>{' '}
                                        rows need a look and will be skipped.
                                    </li>
                                )}
                            </ul>
                        )}
                        {preview.examples.length > 0 && (
                            <p className="text-sm text-muted-foreground">
                                For example:{' '}
                                {preview.examples
                                    .map((e) =>
                                        Object.values(e)
                                            .filter(Boolean)
                                            .join(', '),
                                    )
                                    .join(' · ')}
                            </p>
                        )}
                        {preview.problems.length > 0 && (
                            <details>
                                <summary className="cursor-pointer text-sm underline">
                                    See the rows that need a look
                                </summary>
                                <ul className="mt-2 grid gap-1 text-sm">
                                    {preview.problems.map((p) => (
                                        <li key={p.row}>
                                            Row {p.row}: {p.why}
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        )}
                        <div>
                            <Button
                                size="lg"
                                disabled={
                                    preview.missing.length > 0 ||
                                    preview.ready === 0
                                }
                                onClick={() =>
                                    router.post(
                                        `/setup/import/${im.ulid}/confirm`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {what.go}
                            </Button>
                        </div>
                    </section>
                )}

                {!reviewing && (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <h2 className="text-xl font-semibold">
                            <Badge>
                                {im.status === 'undone'
                                    ? 'Undone'
                                    : 'Brought in'}
                            </Badge>
                        </h2>
                        {im.summary && (
                            <p className="text-base">
                                {Object.entries(im.summary)
                                    .filter(([k]) => k !== 'handed_to_crm')
                                    .map(([k, v]) => `${words(k)}: ${v}`)
                                    .join(' · ')}
                            </p>
                        )}
                        {im.crm_import && (
                            <Link
                                className="underline"
                                href={`/contact-imports/${im.crm_import}`}
                            >
                                Watch your people come in
                            </Link>
                        )}
                        {im.can_undo && (
                            <div>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            `/setup/import/${im.ulid}/undo`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Undo (for 24 hours)
                                </Button>
                            </div>
                        )}
                    </section>
                )}

                {reviewing && (
                    <form
                        className="grid gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put(`/setup/import/${im.ulid}`, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <h2 className="text-xl font-semibold">
                            Which column is which
                        </h2>
                        <label className="grid max-w-sm gap-1 text-base">
                            This file holds
                            <select
                                className="h-10 rounded-md border bg-background px-2"
                                value={form.data.kind}
                                onChange={(e) =>
                                    form.setData(
                                        'kind',
                                        e.target
                                            .value as Props['import']['kind'],
                                    )
                                }
                            >
                                {Object.entries(kinds).map(([k, label]) => (
                                    <option key={k} value={k}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-base">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3 font-medium">
                                            Column in your file
                                        </th>
                                        <th className="p-3 font-medium">
                                            Goes into
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {im.headers.map((h) => (
                                        <tr key={h} className="border-t">
                                            <td className="p-3">{h}</td>
                                            <td className="p-3">
                                                <select
                                                    aria-label={`What "${h}" holds`}
                                                    className="h-10 rounded-md border bg-background px-2"
                                                    value={
                                                        form.data.mapping[h] ??
                                                        ''
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'mapping',
                                                            {
                                                                ...form.data
                                                                    .mapping,
                                                                [h]:
                                                                    e.target
                                                                        .value ||
                                                                    null,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Leave out
                                                    </option>
                                                    {fields[form.data.kind].map(
                                                        (f) => (
                                                            <option
                                                                key={f}
                                                                value={f}
                                                            >
                                                                {words(f)}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {form.errors.mapping && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {form.errors.mapping}
                            </p>
                        )}
                        <div>
                            <Button
                                type="submit"
                                variant="outline"
                                size="lg"
                                disabled={form.processing}
                            >
                                Save my changes and check again
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </>
    );
}

SetupImportShow.layout = {
    breadcrumbs: [{ title: 'Bring your old files', href: '/setup/import' }],
};
