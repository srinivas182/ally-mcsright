import { Head, Link, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Import = {
    ulid: string;
    filename: string;
    label: string;
    kind: string;
    rows: number;
    status: 'reviewing' | 'importing' | 'done' | 'undone' | 'failed';
    created_at: string;
};

type Props = {
    imports: Import[];
    kinds: Record<string, string>;
    sources: string[];
};

const STATUS: Record<Import['status'], string> = {
    reviewing: 'Waiting for you to check',
    importing: 'Coming in',
    done: 'Brought in',
    undone: 'Undone',
    failed: 'Failed',
};

/** SU1: drop in the export files from the old system; each is recognised and previewed before anything is saved. */
export default function SetupImport({ imports, kinds, sources }: Props) {
    const form = useForm<{ files: File[]; kind: string }>({
        files: [],
        kind: '',
    });

    const errors = form.errors as Record<string, string | undefined>;
    const fileError = errors.file ?? errors.files ?? errors['files.0'];

    return (
        <>
            <Head title="Bring your old files" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Bring your old files"
                    description="Drop in the spreadsheets your old systems let you download: your people, your agents and your deals. We work out what each file is and which column is which, and show you what will happen. Nothing is saved until you say yes."
                />
                <form
                    className="grid gap-4 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/setup/import', { forceFormData: true });
                    }}
                >
                    <label className="grid gap-2 text-base">
                        Your files (CSV or Excel, up to 10 at once)
                        <input
                            type="file"
                            multiple
                            accept=".csv,.txt,.xlsx"
                            className="text-base"
                            onChange={(e) =>
                                form.setData(
                                    'files',
                                    Array.from(e.target.files ?? []),
                                )
                            }
                        />
                    </label>
                    <label className="grid max-w-sm gap-2 text-base">
                        What's in them?
                        <select
                            className="h-10 rounded-md border bg-background px-2"
                            value={form.data.kind}
                            onChange={(e) =>
                                form.setData('kind', e.target.value)
                            }
                        >
                            <option value="">Work it out for me</option>
                            {Object.entries(kinds).map(([k, label]) => (
                                <option key={k} value={k}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    {fileError && (
                        <p role="alert" className="text-sm text-destructive">
                            {fileError}
                        </p>
                    )}
                    <div>
                        <Button
                            size="lg"
                            type="submit"
                            disabled={
                                form.data.files.length === 0 || form.processing
                            }
                        >
                            Look at my files
                        </Button>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Recognised straight away: {sources.join(', ')}. Any
                        other spreadsheet works too.
                    </p>
                </form>

                <ul className="grid gap-3">
                    {imports.map((i) => (
                        <li
                            key={i.ulid}
                            className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                        >
                            <div>
                                <p className="font-medium">{i.filename}</p>
                                <p className="text-sm text-muted-foreground">
                                    {i.label} · {i.rows} rows
                                </p>
                            </div>
                            <div className="flex items-center gap-3">
                                <Badge
                                    variant={
                                        i.status === 'reviewing'
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {STATUS[i.status]}
                                </Badge>
                                <Button asChild variant="outline">
                                    <Link href={`/setup/import/${i.ulid}`}>
                                        {i.status === 'reviewing'
                                            ? 'Check it'
                                            : 'See it'}
                                    </Link>
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

SetupImport.layout = {
    breadcrumbs: [{ title: 'Bring your old files', href: '/setup/import' }],
};
