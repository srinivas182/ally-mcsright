import { Head, Link, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type LibraryForm = {
    id: number;
    name: string;
    category: string | null;
    edition: string;
    added: boolean;
    your_edition: string | null;
    update: boolean;
};
type Props = {
    available: { key: string; name: string; needs_member: boolean }[];
    libraries: {
        ulid: string;
        name: string;
        member_id: string | null;
        member_verified_at: string | null;
        last_synced_at: string | null;
        last_error: string | null;
        forms: LibraryForm[];
    }[];
    sources: { key: string; label: string }[];
    comparison: {
        form: string;
        from: string | null;
        to: string;
        added: string[];
        removed: string[];
        fields_added: string[];
        fields_removed: string[];
    } | null;
    imported:
        | {
              name: string;
              template: string;
              fields: number;
              filled_automatically: number;
              needs_signer: number;
          }[]
        | null;
};

/** Association form libraries (connect, add forms, new editions) and imports from other systems. */
export default function FormLibraries({
    available,
    libraries,
    sources,
    comparison,
    imported,
}: Props) {
    const connected = libraries.map((l) => l.name);
    const connect = useForm({
        connector: available[0]?.key ?? '',
        member_id: '',
        last_name: '',
    });
    const upload = useForm<{ source: string; files: File[] }>({
        source: sources[0]?.key ?? 'pdf',
        files: [],
    });
    const chosen = available.find((a) => a.key === connect.data.connector);
    const opts = { preserveScroll: true };

    return (
        <>
            <Head title="Form libraries" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Form libraries"
                    description="Official association forms with their fields already placed, alerts when a new edition comes out, and imports from other systems."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/forms/templates">Your forms</Link>
                        </Button>
                    }
                />

                {comparison && (
                    <section className="grid gap-2 rounded-lg border border-sky-300 bg-sky-50/50 p-4 text-sm">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-semibold">
                                {comparison.form}: edition{' '}
                                {comparison.from ?? '—'} → {comparison.to}
                            </h2>
                            <Button
                                size="sm"
                                variant="ghost"
                                className="ml-auto"
                                onClick={() => router.get('/forms/libraries')}
                            >
                                Close
                            </Button>
                        </div>
                        <div className="grid gap-3 md:grid-cols-2">
                            <div>
                                <p className="text-xs font-medium text-red-800">
                                    Wording removed
                                </p>
                                {comparison.removed.length === 0 ? (
                                    <p className="text-muted-foreground">
                                        None
                                    </p>
                                ) : (
                                    comparison.removed.map((l, i) => (
                                        <p key={i} className="line-through">
                                            {l}
                                        </p>
                                    ))
                                )}
                            </div>
                            <div>
                                <p className="text-xs font-medium text-emerald-800">
                                    Wording added
                                </p>
                                {comparison.added.length === 0 ? (
                                    <p className="text-muted-foreground">
                                        None
                                    </p>
                                ) : (
                                    comparison.added.map((l, i) => (
                                        <p key={i} className="font-medium">
                                            {l}
                                        </p>
                                    ))
                                )}
                            </div>
                        </div>
                        {(comparison.fields_added.length > 0 ||
                            comparison.fields_removed.length > 0) && (
                            <p className="text-xs">
                                Fields added:{' '}
                                {comparison.fields_added.join(', ') || 'none'} ·
                                Fields removed:{' '}
                                {comparison.fields_removed.join(', ') || 'none'}
                            </p>
                        )}
                    </section>
                )}

                {libraries.map((l) => (
                    <section
                        key={l.ulid}
                        className="grid gap-2 rounded-lg border p-4 text-sm"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-semibold">{l.name}</h2>
                            {l.member_verified_at && (
                                <Badge variant="secondary">
                                    Member {l.member_id} verified
                                </Badge>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                className="ml-auto"
                                onClick={() =>
                                    router.post(
                                        `/forms/libraries/${l.ulid}/sync`,
                                        {},
                                        opts,
                                    )
                                }
                            >
                                Check for new editions
                            </Button>
                        </div>
                        {l.last_error && (
                            <p role="alert" className="text-destructive">
                                Last check failed: {l.last_error}
                            </p>
                        )}
                        <ul className="divide-y rounded border">
                            {l.forms.map((f) => (
                                <li
                                    key={f.id}
                                    className="flex flex-wrap items-center gap-2 p-2"
                                >
                                    <span>{f.name}</span>
                                    {f.category && (
                                        <span className="text-xs text-muted-foreground">
                                            {f.category}
                                        </span>
                                    )}
                                    <span className="text-xs text-muted-foreground">
                                        Edition {f.edition}
                                    </span>
                                    <span className="ml-auto flex flex-wrap items-center gap-2">
                                        {!f.added ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        `/forms/library-forms/${f.id}/import`,
                                                        {},
                                                        opts,
                                                    )
                                                }
                                            >
                                                Add to your forms
                                            </Button>
                                        ) : f.update ? (
                                            <>
                                                <Badge variant="destructive">
                                                    New edition (you have{' '}
                                                    {f.your_edition})
                                                </Badge>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        router.get(
                                                            '/forms/libraries',
                                                            { compare: f.id },
                                                        )
                                                    }
                                                >
                                                    See what changed
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/forms/library-forms/${f.id}/import`,
                                                            {},
                                                            opts,
                                                        )
                                                    }
                                                >
                                                    Update
                                                </Button>
                                            </>
                                        ) : (
                                            <Badge variant="secondary">
                                                Added · up to date
                                            </Badge>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}

                {available.some((a) => !connected.includes(a.name)) && (
                    <section className="grid gap-2 rounded-lg border p-4 text-sm">
                        <h2 className="font-semibold">Connect a library</h2>
                        <form
                            className="flex flex-wrap items-center gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                connect.post('/forms/libraries', opts);
                            }}
                        >
                            <select
                                aria-label="Library"
                                className="rounded border bg-background px-2 py-1.5"
                                value={connect.data.connector}
                                onChange={(e) =>
                                    connect.setData('connector', e.target.value)
                                }
                            >
                                {available
                                    .filter((a) => !connected.includes(a.name))
                                    .map((a) => (
                                        <option key={a.key} value={a.key}>
                                            {a.name}
                                        </option>
                                    ))}
                            </select>
                            {chosen?.needs_member && (
                                <>
                                    <Input
                                        aria-label="Member number (NRDS)"
                                        placeholder="Member number (NRDS)"
                                        inputMode="numeric"
                                        className="w-48"
                                        value={connect.data.member_id}
                                        onChange={(e) =>
                                            connect.setData(
                                                'member_id',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <Input
                                        aria-label="Member's last name"
                                        placeholder="Member's last name"
                                        className="w-48"
                                        value={connect.data.last_name}
                                        onChange={(e) =>
                                            connect.setData(
                                                'last_name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </>
                            )}
                            <Button size="sm" disabled={connect.processing}>
                                Connect
                            </Button>
                        </form>
                        {Object.values(connect.errors)[0] && (
                            <p role="alert" className="text-destructive">
                                {Object.values(connect.errors)[0]}
                            </p>
                        )}
                    </section>
                )}
                {available.length === 0 && libraries.length === 0 && (
                    <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                        Association libraries appear here as RightAlly signs
                        licences with each association. Meanwhile, import your
                        association's PDF package below.
                    </p>
                )}

                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">Import forms</h2>
                    <p className="text-muted-foreground">
                        Moving from dotloop, SkySlope or zipForm, or have your
                        association's PDF package? Upload the PDFs or a ZIP.
                        Fillable fields are placed and matched to deal details
                        automatically; check each form before first use.
                    </p>
                    <form
                        className="flex flex-wrap items-center gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            upload.post('/forms/import', {
                                ...opts,
                                forceFormData: true,
                                onSuccess: () => upload.reset('files'),
                            });
                        }}
                    >
                        <select
                            aria-label="Where the forms come from"
                            className="rounded border bg-background px-2 py-1.5"
                            value={upload.data.source}
                            onChange={(e) =>
                                upload.setData('source', e.target.value)
                            }
                        >
                            {sources.map((s) => (
                                <option key={s.key} value={s.key}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                        <Input
                            type="file"
                            multiple
                            accept="application/pdf,.pdf,.zip,application/zip"
                            aria-label="PDF files or a ZIP"
                            className="w-72"
                            onChange={(e) =>
                                upload.setData(
                                    'files',
                                    Array.from(e.target.files ?? []),
                                )
                            }
                        />
                        <Button
                            size="sm"
                            disabled={
                                upload.processing ||
                                upload.data.files.length === 0
                            }
                        >
                            Import
                        </Button>
                    </form>
                    {Object.values(upload.errors)[0] && (
                        <p role="alert" className="text-destructive">
                            {Object.values(upload.errors)[0]}
                        </p>
                    )}
                    {imported && (
                        <ul className="grid gap-1 rounded border border-emerald-300 bg-emerald-50 p-2 text-emerald-900">
                            {imported.map((r) => (
                                <li key={r.template}>
                                    {r.name}: {r.fields} field(s) placed,{' '}
                                    {r.filled_automatically} filled from the
                                    deal
                                    {r.needs_signer > 0 &&
                                        ` · ${r.needs_signer} signature field(s) need a signer assigned`}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
