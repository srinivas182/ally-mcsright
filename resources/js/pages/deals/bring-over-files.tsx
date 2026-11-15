import { Head, Link, router, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
type Linked = { ulid: string; app: string; pay_from: string | null };
type Waiting = {
    ulid: string;
    street: string;
    status: 'needs_gci' | 'needs_review';
    note: string | null;
    from: string | null;
};

export default function BringOverFiles({
    imports,
    limits,
    connections = [],
    syncedCommission = 'outside',
    owner = false,
    waiting = [],
}: {
    imports: Import[];
    limits: { max_mb: number };
    connections?: Linked[];
    syncedCommission?: 'outside' | 'payouts';
    owner?: boolean;
    waiting?: Waiting[];
}) {
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <>
            <Head title="Switch from another app" />
            <div className="grid max-w-3xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Switch from another app"
                    description="Deals that close in dotloop, SkySlope or Lone Wolf are paid here, and their documents are filed into the right deals."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/deals">Back to deals</Link>
                        </Button>
                    }
                />

                {waiting.length > 0 && (
                    <section className="grid gap-2 rounded-lg border border-destructive p-4">
                        <h2 className="font-medium">
                            Closed deals waiting for you ({waiting.length})
                        </h2>
                        <ul className="grid gap-2">
                            {waiting.map((w) => (
                                <li key={w.ulid}>
                                    <Link
                                        href={`/deals/${w.ulid}`}
                                        className="font-medium underline"
                                    >
                                        {w.street}
                                    </Link>
                                    {w.from ? ` (from ${w.from})` : ''}:{' '}
                                    {w.note}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="grid gap-3 rounded-lg border p-4">
                    <h2 className="font-medium">
                        Commission for deals that close in your old app
                    </h2>
                    <p>
                        A deal that closes there is paid here automatically, on
                        the agent's plan: split, fees, cap and revenue share.
                        Deals that closed before the date below are history,
                        paid in your old system.
                    </p>
                    {connections.length === 0 && (
                        <p className="text-muted-foreground">
                            Connect dotloop, SkySlope or Lone Wolf under Apps
                            first.
                        </p>
                    )}
                    {connections.map((c) => (
                        <PayFrom key={c.ulid} linked={c} owner={owner} />
                    ))}
                    <fieldset className="grid gap-2" disabled={!owner}>
                        <legend className="mb-1">
                            The agent's own commission on these deals
                        </legend>
                        {(
                            [
                                [
                                    'outside',
                                    'Paid outside RightAlly (at closing, or by our old back office)',
                                ],
                                [
                                    'payouts',
                                    'Paid through RightAlly payouts, once we record the money received',
                                ],
                            ] as const
                        ).map(([value, text]) => (
                            <label
                                key={value}
                                className="flex items-center gap-3"
                            >
                                <input
                                    type="radio"
                                    className="size-5"
                                    checked={syncedCommission === value}
                                    onChange={() =>
                                        router.post(
                                            '/deals/pay-settings',
                                            { synced_commission: value },
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                                {text}
                            </label>
                        ))}
                        <p className="text-sm text-muted-foreground">
                            Either way, revenue share and bonuses go to each
                            agent's wallet, paid in your payout runs or with Get
                            paid now.
                        </p>
                    </fieldset>
                </section>

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

function PayFrom({ linked, owner }: { linked: Linked; owner: boolean }) {
    const form = useForm({
        connection: linked.ulid,
        pay_from: linked.pay_from ?? '',
    });

    return (
        <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/deals/pay-from', { preserveScroll: true });
            }}
        >
            <label className="grid gap-1">
                {linked.app}: pay deals that close from
                <Input
                    type="date"
                    value={form.data.pay_from}
                    disabled={!owner}
                    onChange={(e) => form.setData('pay_from', e.target.value)}
                />
            </label>
            {owner && (
                <Button
                    variant="outline"
                    disabled={form.processing || !form.data.pay_from}
                >
                    Save
                </Button>
            )}
            {form.errors.pay_from && (
                <p className="w-full text-destructive">
                    {form.errors.pay_from}
                </p>
            )}
        </form>
    );
}
