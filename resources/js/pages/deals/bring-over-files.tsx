import { Head, Link, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Import = {
    ulid: string;
    filename: string;
    status: 'queued' | 'done' | 'failed';
    files: number;
    filed: number;
    deals: number;
    unmatched: string[];
    error: string | null;
    at: string;
};

/**
 * Bring over files (specs/connect/12): upload a SkySlope or Lone Wolf file export once; each PDF is filed into the
 * deal its folder names. Folders that match no deal are listed, never guessed.
 */
export default function BringOverFiles({
    imports,
    limits,
}: {
    imports: Import[];
    limits: { max_mb: number };
}) {
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <>
            <Head title="Bring over files" />
            <div className="grid max-w-3xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Bring over files"
                    description="Your old transaction app's documents, filed into the right deals."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/deals">Back to deals</Link>
                        </Button>
                    }
                />

                <section className="grid gap-3 rounded-lg border p-4">
                    <h2 className="font-medium">How it works</h2>
                    <ol className="ml-5 list-decimal space-y-1">
                        <li>
                            Connect SkySlope or Lone Wolf under Apps first, so
                            your deals are here.
                        </li>
                        <li>
                            Ask SkySlope or Lone Wolf support for a full export
                            of your transaction files (a ZIP with a folder for
                            each transaction).
                        </li>
                        <li>
                            Upload the ZIP here. Each file goes into the deal
                            whose address its folder names, under "Unsorted
                            documents".
                        </li>
                    </ol>
                    <form
                        className="grid gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/deals/bring-over-files', {
                                forceFormData: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <label className="grid gap-1">
                            The export (.zip, up to {limits.max_mb} MB)
                            <input
                                type="file"
                                accept=".zip,application/zip"
                                className="h-12 rounded-md border bg-background px-3 py-2"
                                onChange={(e) =>
                                    form.setData(
                                        'file',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </label>
                        {form.errors.file && (
                            <p className="text-destructive">
                                {form.errors.file}
                            </p>
                        )}
                        {form.progress && (
                            <p className="text-muted-foreground">
                                Uploading… {form.progress.percentage}%
                            </p>
                        )}
                        <div>
                            <Button
                                size="lg"
                                disabled={form.processing || !form.data.file}
                            >
                                Upload and file
                            </Button>
                        </div>
                    </form>
                </section>

                {imports.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="font-medium">Earlier uploads</h2>
                        {imports.map((i) => (
                            <div
                                key={i.ulid}
                                className="grid gap-1 rounded-lg border p-4"
                            >
                                <p className="font-medium">{i.filename}</p>
                                {i.status === 'queued' && (
                                    <p className="text-muted-foreground">
                                        Filing now… refresh in a few minutes.
                                    </p>
                                )}
                                {i.status === 'failed' && (
                                    <p className="text-destructive">
                                        {i.error ??
                                            "That ZIP couldn't be read."}
                                    </p>
                                )}
                                {i.status === 'done' && (
                                    <p>
                                        {i.filed.toLocaleString()} of{' '}
                                        {i.files.toLocaleString()} files filed
                                        into {i.deals.toLocaleString()} deals.
                                    </p>
                                )}
                                {i.unmatched.length > 0 && (
                                    <details>
                                        <summary className="cursor-pointer">
                                            {i.unmatched.length} folder(s)
                                            didn't match a deal
                                        </summary>
                                        <ul className="mt-2 ml-5 list-disc text-sm text-muted-foreground">
                                            {i.unmatched.map((f) => (
                                                <li key={f}>{f}</li>
                                            ))}
                                        </ul>
                                        <p className="mt-2 text-sm">
                                            Add those deals (or check their
                                            address), then upload the export
                                            again: files already filed are
                                            skipped.
                                        </p>
                                    </details>
                                )}
                            </div>
                        ))}
                    </section>
                )}
            </div>
        </>
    );
}
