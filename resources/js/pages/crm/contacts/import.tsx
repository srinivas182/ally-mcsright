import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { StatCard } from '@/components/ui/stat-card';

type Props = {
    import: {
        ulid: string;
        name: string;
        status: 'uploaded' | 'running' | 'done' | 'failed';
        headers: string[];
        rows: number;
        created: number;
        duplicates: number;
        error_count: number;
        errors: { row: number; reason: string }[];
    };
    guess: Record<string, string>;
    preview: Record<string, string>[];
    fields: string[];
    sources: string[];
    isManager: boolean;
};

const label = (v: string) => v.replace(/_/g, ' ');

export default function ContactImportPage({
    import: imp,
    guess,
    preview,
    fields,
    sources,
    isManager,
}: Props) {
    const form = useForm({
        mapping: guess,
        source: 'import',
        tag: '',
        origin: 'company',
    });
    const mappingError = (form.errors as Record<string, string | undefined>)
        .mapping;

    useEffect(() => {
        if (imp.status !== 'running') {
            return;
        }
        const timer = setInterval(
            () => router.reload({ only: ['import'] }),
            3000,
        );
        return () => clearInterval(timer);
    }, [imp.status]);

    return (
        <>
            <Head title="Import contacts" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader title="Import contacts" description={imp.name} />

                {imp.status === 'uploaded' && (
                    <form
                        className="grid gap-6"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/contact-imports/${imp.ulid}/run`);
                        }}
                    >
                        <p className="text-sm text-muted-foreground">
                            We matched your columns automatically. Check them,
                            then import. Contacts already in the brokerage (same
                            email or phone) are skipped. Texting consent is
                            never imported; it must be given by each person.
                        </p>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-2 font-medium">
                                            Your column
                                        </th>
                                        <th className="p-2 font-medium">
                                            Becomes
                                        </th>
                                        <th className="p-2 font-medium">
                                            Example
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {imp.headers.map((h) => (
                                        <tr key={h} className="border-t">
                                            <td className="p-2">{h}</td>
                                            <td className="p-2">
                                                <select
                                                    aria-label={`Field for ${h}`}
                                                    className="rounded border bg-background px-2 py-1"
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
                                                                [h]: e.target
                                                                    .value,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Skip
                                                    </option>
                                                    {fields.map((f) => (
                                                        <option
                                                            key={f}
                                                            value={f}
                                                        >
                                                            {label(f)}
                                                        </option>
                                                    ))}
                                                </select>
                                            </td>
                                            <td className="p-2 text-muted-foreground">
                                                {preview[0]?.[h] ?? ''}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {mappingError && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {mappingError}
                            </p>
                        )}
                        <div className="grid gap-4 sm:grid-cols-3">
                            <FormField
                                label="Source"
                                error={form.errors.source}
                            >
                                {(c) => (
                                    <select
                                        {...c}
                                        className="rounded border bg-background px-2 py-1.5"
                                        value={form.data.source}
                                        onChange={(e) =>
                                            form.setData(
                                                'source',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {sources.map((s) => (
                                            <option key={s} value={s}>
                                                {label(s)}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </FormField>
                            <FormField
                                label="Tag every contact (optional)"
                                error={form.errors.tag}
                            >
                                {(c) => (
                                    <Input
                                        {...c}
                                        value={form.data.tag}
                                        onChange={(e) =>
                                            form.setData('tag', e.target.value)
                                        }
                                    />
                                )}
                            </FormField>
                            {isManager && (
                                <FormField label="These contacts are">
                                    {(c) => (
                                        <select
                                            {...c}
                                            className="rounded border bg-background px-2 py-1.5"
                                            value={form.data.origin}
                                            onChange={(e) =>
                                                form.setData(
                                                    'origin',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="company">
                                                Company contacts
                                            </option>
                                            <option value="personal">
                                                My personal sphere
                                            </option>
                                        </select>
                                    )}
                                </FormField>
                            )}
                        </div>
                        <Button
                            type="submit"
                            className="justify-self-start"
                            disabled={form.processing}
                        >
                            Import contacts
                        </Button>
                    </form>
                )}

                {imp.status === 'running' && (
                    <p role="status">Importing… this page updates by itself.</p>
                )}

                {(imp.status === 'done' || imp.status === 'failed') && (
                    <>
                        <div className="grid gap-4 sm:grid-cols-4">
                            <StatCard
                                label="Rows read"
                                value={String(imp.rows)}
                            />
                            <StatCard
                                label="Contacts added"
                                value={String(imp.created)}
                            />
                            <StatCard
                                label="Skipped (already in the brokerage)"
                                value={String(imp.duplicates)}
                            />
                            <StatCard
                                label="Rows with problems"
                                value={String(imp.error_count)}
                            />
                        </div>
                        {imp.errors.length > 0 && (
                            <ul className="divide-y rounded-lg border text-sm">
                                {imp.errors.map((e) => (
                                    <li
                                        key={`${e.row}-${e.reason}`}
                                        className="p-2"
                                    >
                                        {e.row > 0 ? `Row ${e.row}: ` : ''}
                                        {e.reason}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Button asChild className="justify-self-start">
                            <Link href="/contacts">Go to contacts</Link>
                        </Button>
                    </>
                )}
            </div>
        </>
    );
}

ContactImportPage.layout = {
    breadcrumbs: [
        { title: 'Contacts', href: '/contacts' },
        { title: 'Import', href: '#' },
    ],
};
