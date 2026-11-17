import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Pack = {
    ulid: string;
    from: string;
    to: string;
    status: 'queued' | 'running' | 'ready' | 'failed' | 'expired';
    files: number | null;
    bytes: number | null;
    ready_at: string | null;
    expires_at: string | null;
    requested_by: string;
};

type Auditor = {
    ulid: string;
    name: string;
    email: string;
    firm: string;
    status: 'invited' | 'active' | 'expired' | 'ended';
    expires_at: string;
};

type Props = {
    packs: Pack[];
    auditors: Auditor[];
    password: { pack: string; password: string } | null;
    defaults: { from: string; to: string };
    can: { auditors: boolean };
    limits: { min_days: number; max_days: number; keep_days: number };
};

const PACK_STATUS: Record<Pack['status'], string> = {
    queued: 'Being prepared',
    running: 'Being prepared',
    ready: 'Ready',
    failed: 'Failed — try again',
    expired: 'Expired',
};

const AUDITOR_STATUS: Record<Auditor['status'], string> = {
    invited: 'Invited',
    active: 'Has access',
    expired: 'Access ended (date passed)',
    ended: 'Access ended',
};

/** FH2: one-click audit pack for a CPA or regulator, and read-only access for an outside auditor. */
export default function Audit({
    packs,
    auditors,
    password,
    defaults,
    can,
    limits,
}: Props) {
    const pack = useForm({ from: defaults.from, to: defaults.to });
    const invite = useForm({
        email: '',
        first_name: '',
        last_name: '',
        firm: '',
        days: 30,
    });

    return (
        <>
            <Head title="Audit pack" />
            <div className="flex flex-col gap-8 p-4 md:p-6">
                <PageHeader
                    title="Audit pack"
                    description="Everything your CPA or a state regulator asks for, in one password-protected file: statements as closed, every entry, who approved what, reconciliations, payouts and the 1099 tie-out. Tax ID and bank account numbers are never included."
                />

                <section className="grid gap-4" aria-labelledby="pack-title">
                    <h2 id="pack-title" className="text-xl font-semibold">
                        Make an audit pack
                    </h2>
                    <form
                        className="flex flex-wrap items-end gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            pack.post('/finance/audit/packs', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <label className="grid gap-1 text-base">
                            From
                            <Input
                                type="date"
                                value={pack.data.from}
                                onChange={(e) =>
                                    pack.setData('from', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-base">
                            To
                            <Input
                                type="date"
                                value={pack.data.to}
                                onChange={(e) =>
                                    pack.setData('to', e.target.value)
                                }
                            />
                        </label>
                        <Button
                            size="lg"
                            type="submit"
                            disabled={pack.processing}
                        >
                            Make the pack
                        </Button>
                    </form>
                    {pack.errors.from && (
                        <p role="alert" className="text-sm text-destructive">
                            {pack.errors.from}
                        </p>
                    )}
                    <p className="text-sm text-muted-foreground">
                        Packs are kept for {limits.keep_days} days. Downloading
                        or showing the password asks for your password again.
                    </p>
                    {password && (
                        <p
                            role="status"
                            className="rounded-md border p-3 text-base"
                        >
                            Password for this pack:{' '}
                            <span className="font-mono font-semibold select-all">
                                {password.password}
                            </span>
                            . Send it to your auditor separately from the file.
                        </p>
                    )}
                    <ul className="grid gap-3">
                        {packs.map((p) => (
                            <li
                                key={p.ulid}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                            >
                                <div>
                                    <p className="font-medium">
                                        {p.from} to {p.to}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Made by {p.requested_by}
                                        {p.files ? ` · ${p.files} files` : ''}
                                        {p.expires_at && p.status === 'ready'
                                            ? ` · kept until ${p.expires_at.slice(0, 10)}`
                                            : ''}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-3">
                                    <Badge
                                        variant={
                                            p.status === 'ready'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {PACK_STATUS[p.status]}
                                    </Badge>
                                    {p.status === 'ready' && (
                                        <>
                                            <Button asChild variant="outline">
                                                <a
                                                    href={`/finance/audit/packs/${p.ulid}`}
                                                >
                                                    Download
                                                </a>
                                            </Button>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        `/finance/audit/packs/${p.ulid}/password`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Show password
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                {can.auditors && (
                    <section
                        className="grid gap-4"
                        aria-labelledby="auditor-title"
                    >
                        <h2
                            id="auditor-title"
                            className="text-xl font-semibold"
                        >
                            Give your auditor read-only access
                        </h2>
                        <p className="text-base text-muted-foreground">
                            They can read the books and download statements and
                            audit packs. They can't change anything, and their
                            access ends by itself.
                        </p>
                        <form
                            className="grid gap-3 sm:grid-cols-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                invite.post('/finance/audit/auditors', {
                                    preserveScroll: true,
                                    onSuccess: () => invite.reset(),
                                });
                            }}
                        >
                            {(
                                [
                                    ['first_name', 'First name', 'text'],
                                    ['last_name', 'Last name', 'text'],
                                    ['email', 'Email', 'email'],
                                    ['firm', 'Firm (optional)', 'text'],
                                ] as const
                            ).map(([key, label, type]) => (
                                <label
                                    key={key}
                                    className="grid gap-1 text-base"
                                >
                                    {label}
                                    <Input
                                        type={type}
                                        value={invite.data[key]}
                                        onChange={(e) =>
                                            invite.setData(key, e.target.value)
                                        }
                                    />
                                    {invite.errors[key] && (
                                        <span
                                            role="alert"
                                            className="text-sm text-destructive"
                                        >
                                            {invite.errors[key]}
                                        </span>
                                    )}
                                </label>
                            ))}
                            <label className="grid gap-1 text-base">
                                Access ends after (days, {limits.min_days}–
                                {limits.max_days})
                                <Input
                                    type="number"
                                    min={limits.min_days}
                                    max={limits.max_days}
                                    value={invite.data.days}
                                    onChange={(e) =>
                                        invite.setData(
                                            'days',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                                {invite.errors.days && (
                                    <span
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {invite.errors.days}
                                    </span>
                                )}
                            </label>
                            <div className="flex items-end">
                                <Button
                                    size="lg"
                                    type="submit"
                                    disabled={invite.processing}
                                >
                                    Send invitation
                                </Button>
                            </div>
                        </form>
                        <ul className="grid gap-3">
                            {auditors.map((a) => (
                                <li
                                    key={a.ulid}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {a.name}
                                            {a.firm ? `, ${a.firm}` : ''}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {a.email} · ends {a.expires_at}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <Badge variant="secondary">
                                            {AUDITOR_STATUS[a.status]}
                                        </Badge>
                                        {(a.status === 'invited' ||
                                            a.status === 'active') && (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        `/finance/audit/auditors/${a.ulid}/revoke`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                End access now
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

Audit.layout = {
    breadcrumbs: [{ title: 'Audit pack', href: '/finance/audit' }],
};
