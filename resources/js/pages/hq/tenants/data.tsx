import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Export = {
    ulid: string;
    kind: string;
    status: string;
    bytes: number | null;
    tables: number;
    rows: number;
    files: number;
    by: string | null;
    created_at: string;
    expires_at: string | null;
};
type Restore = {
    ulid: string;
    status: string;
    reason: string;
    requested_by: string;
    approved_by: string | null;
    export: string | null;
    error: string | null;
    created_at: string;
    finished_at: string | null;
    mine: boolean;
};

/** HQ → a brokerage → Data (DS1): where its data lives, backups to download, and two-person restores. */
export default function TenantData({
    tenant,
    exports,
    restores,
    password,
}: {
    tenant: {
        ulid: string;
        name: string;
        cell: string | null;
        connection: string;
    };
    exports: Export[];
    restores: Restore[];
    password: { export: string; password: string } | null;
}) {
    const base = `/hq/tenants/${tenant.ulid}/data`;
    const ask = useForm({ export: '', reason: '', confirm: '' });
    const restorable = exports.filter(
        (e) =>
            e.status === 'ready' &&
            (e.kind === 'backup' || e.kind === 'safety'),
    );

    return (
        <>
            <Head title={`${tenant.name} · Data`} />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title={`${tenant.name}: data`}
                    description={`Cell: ${tenant.cell ?? 'none'} · database connection: ${tenant.connection}`}
                    actions={
                        <Button onClick={() => router.post(`${base}/export`)}>
                            Make a backup now
                        </Button>
                    }
                />
                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">
                        Exports and backups
                    </h2>
                    {exports.length === 0 && (
                        <p className="text-muted-foreground">None yet.</p>
                    )}
                    {exports.map((e) => (
                        <div
                            key={e.ulid}
                            className="flex flex-wrap items-center gap-2 rounded border p-3 text-sm"
                        >
                            <Badge variant="outline">{e.kind}</Badge>
                            <Badge
                                variant={
                                    e.status === 'ready'
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {e.status}
                            </Badge>
                            <span>{e.created_at.slice(0, 16)}</span>
                            <span className="text-muted-foreground">
                                {e.rows} rows · {e.tables} tables · {e.files}{' '}
                                files{e.by ? ` · by ${e.by}` : ''}
                            </span>
                            {e.status === 'ready' && e.kind !== 'portable' && (
                                <>
                                    <a
                                        className="underline"
                                        href={`${base}/exports/${e.ulid}`}
                                    >
                                        Download
                                    </a>
                                    <button
                                        type="button"
                                        className="underline"
                                        onClick={() =>
                                            router.post(
                                                `${base}/exports/${e.ulid}/password`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Show password
                                    </button>
                                </>
                            )}
                            {password?.export === e.ulid && (
                                <code className="w-full rounded bg-muted p-2 select-all">
                                    {password.password}
                                </code>
                            )}
                        </div>
                    ))}
                </section>
                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="text-lg font-semibold">
                        Restore this brokerage from a backup
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Puts this brokerage back exactly as it was in the
                        backup; no other brokerage is touched. A safety copy is
                        taken first, and a second HQ person must approve.
                    </p>
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            ask.post(`${base}/restores`, {
                                preserveScroll: true,
                                onSuccess: () => ask.reset(),
                            });
                        }}
                    >
                        <select
                            aria-label="Backup to restore"
                            className="rounded border px-2 py-2"
                            value={ask.data.export}
                            onChange={(e) =>
                                ask.setData('export', e.target.value)
                            }
                        >
                            <option value="">Choose a backup…</option>
                            {restorable.map((e) => (
                                <option key={e.ulid} value={e.ulid}>
                                    {e.kind} · {e.created_at.slice(0, 16)} ·{' '}
                                    {e.rows} rows
                                </option>
                            ))}
                        </select>
                        <input
                            aria-label="Reason"
                            className="rounded border px-3 py-2"
                            placeholder="Why (for the record)"
                            value={ask.data.reason}
                            onChange={(e) =>
                                ask.setData('reason', e.target.value)
                            }
                        />
                        <input
                            aria-label="Type the brokerage's short name to confirm"
                            className="rounded border px-3 py-2"
                            placeholder="Type the brokerage's short name to confirm"
                            value={ask.data.confirm}
                            onChange={(e) =>
                                ask.setData('confirm', e.target.value)
                            }
                        />
                        {Object.values(ask.errors).map((m) => (
                            <p
                                key={m}
                                role="alert"
                                className="text-sm text-red-700"
                            >
                                {m}
                            </p>
                        ))}
                        <Button
                            variant="destructive"
                            className="justify-self-start"
                            disabled={!ask.data.export}
                        >
                            Ask for a restore
                        </Button>
                    </form>
                    {restores.map((r) => (
                        <div
                            key={r.ulid}
                            className="flex flex-wrap items-center gap-2 border-t pt-2 text-sm"
                        >
                            <Badge>{r.status}</Badge>
                            <span>
                                {r.created_at.slice(0, 16)} · asked by{' '}
                                {r.requested_by}
                                {r.approved_by &&
                                    ` · approved by ${r.approved_by}`}
                            </span>
                            <span className="text-muted-foreground">
                                {r.reason}
                            </span>
                            {r.error && (
                                <span className="text-red-700">{r.error}</span>
                            )}
                            {r.status === 'pending' && !r.mine && (
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    onClick={() =>
                                        router.post(
                                            `${base}/restores/${r.ulid}/approve`,
                                        )
                                    }
                                >
                                    Approve and run
                                </Button>
                            )}
                            {r.status === 'pending' && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        router.post(
                                            `${base}/restores/${r.ulid}/cancel`,
                                        )
                                    }
                                >
                                    Cancel
                                </Button>
                            )}
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}
