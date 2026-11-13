import { Head, router, useForm } from '@inertiajs/react';

import { CePanel, type CeProgress } from '@/components/roster/ce-panel';
import { FlowPanel, type Flow } from '@/components/roster/flow-panel';
import {
    SignAgreement,
    type Agreement,
} from '@/components/roster/sign-agreement';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Credential = {
    ulid: string;
    kind: string;
    state_code: string | null;
    number: string | null;
    issuer: string | null;
    coverage_cents: number | null;
    expires_on: string | null;
    status: string;
    verified: boolean;
    verification_note: string | null;
    check_result?: string | null;
};
type Props = {
    agent: { ulid: string; name: string };
    records: {
        credentials: Credential[];
        history: Credential[];
        documents: {
            ulid: string;
            kind: string;
            filename: string;
            created_at: string;
        }[];
    };
    canManage: boolean;
    isSelf: boolean;
    isOwner: boolean;
    onboarding: Flow | null;
    offboarding: Flow | null;
    toSign: Agreement[];
    requests: {
        ulid: string;
        kind: string;
        data: Record<string, string | null>;
        document: string | null;
        submitted_at: string;
    }[];
    ce: CeProgress[];
    canVerify: boolean;
};

const kinds: Record<string, string> = {
    licence: 'Licence',
    eo: 'E&O insurance',
    association: 'Association',
    mls: 'MLS membership',
};
const docKinds: Record<string, string> = {
    agreement: 'Contractor agreement',
    w9: 'W-9',
    licence_copy: 'Licence copy',
    eo_certificate: 'E&O certificate',
    verification: 'Verification screenshot',
    other: 'Other',
};
const select = 'rounded border bg-background px-2 py-1.5';

function title(c: Credential) {
    return c.kind === 'licence'
        ? `${c.state_code} licence ${c.number ?? ''}`
        : c.kind === 'eo'
          ? `E&O — ${c.issuer}${c.coverage_cents ? ` (${usd(c.coverage_cents)})` : ''}`
          : `${c.issuer}${c.number ? ` · ${c.number}` : ''}`;
}

function Row({
    c,
    canManage,
    canVerify,
}: {
    c: Credential;
    canManage: boolean;
    canVerify: boolean;
}) {
    const [note, setNote] = useState('');

    return (
        <li className="grid gap-1 p-3">
            <span className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{title(c)}</span>
                {c.expires_on && (
                    <span className="text-muted-foreground">
                        expires {new Date(c.expires_on).toLocaleDateString()}
                    </span>
                )}
                <Badge
                    variant={
                        c.status === 'expired'
                            ? 'destructive'
                            : c.status === 'expiring'
                              ? 'outline'
                              : 'secondary'
                    }
                    className="capitalize"
                >
                    {c.status === 'ok' ? 'Current' : c.status}
                </Badge>
                {c.kind === 'licence' &&
                    (c.verified ? (
                        <Badge variant="secondary">Verified</Badge>
                    ) : (
                        <Badge variant="outline">Not verified</Badge>
                    ))}
            </span>
            {canVerify && c.kind === 'licence' && (
                <Button
                    size="sm"
                    variant="ghost"
                    className="justify-self-start"
                    onClick={() =>
                        router.post(
                            `/roster/credentials/${c.ulid}/check`,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Check with state records
                </Button>
            )}
            {c.verification_note && (
                <span className="text-xs text-muted-foreground">
                    Checked: {c.verification_note}
                </span>
            )}
            {canManage && c.kind === 'licence' && !c.verified && (
                <span className="flex flex-wrap gap-2">
                    <Input
                        aria-label="How it was checked"
                        placeholder="Looked up on the state licensing site: active until …"
                        className="max-w-md"
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={note.length < 10}
                        onClick={() =>
                            router.post(
                                `/roster/credentials/${c.ulid}/verify`,
                                { note },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Mark verified
                    </Button>
                </span>
            )}
        </li>
    );
}

/** One agent's licences, E&O, memberships and documents. */
export default function RosterAgent({
    agent,
    records,
    canManage,
    isSelf,
    isOwner,
    onboarding,
    offboarding,
    toSign,
    requests,
    ce,
    canVerify,
}: Props) {
    const add = useForm({
        kind: 'licence',
        state_code: '',
        number: '',
        issuer: '',
        coverage: '',
        expires_on: '',
    });
    const doc = useForm<{ kind: string; file: File | null }>({
        kind: 'agreement',
        file: null,
    });
    const opts = { preserveScroll: true };

    return (
        <>
            <Head title={agent.name} />
            <div className="flex max-w-4xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title={agent.name}
                    description="Licences, E&O, memberships and documents."
                />
                {toSign.map((a) => (
                    <SignAgreement key={a.id} agreement={a} name={agent.name} />
                ))}
                {onboarding && (
                    <FlowPanel
                        title="Onboarding"
                        flow={onboarding}
                        canManage={canManage}
                        onComplete={
                            canManage
                                ? () =>
                                      router.post(
                                          `/roster/${agent.ulid}/flows/onboarding/complete`,
                                          {},
                                          { preserveScroll: true },
                                      )
                                : undefined
                        }
                    />
                )}
                {offboarding && (
                    <FlowPanel
                        title="Offboarding"
                        flow={offboarding}
                        canManage={canManage}
                        onComplete={
                            isOwner
                                ? () =>
                                      router.post(
                                          `/roster/${agent.ulid}/flows/offboarding/complete`,
                                          {},
                                          { preserveScroll: true },
                                      )
                                : undefined
                        }
                    />
                )}
                {canManage && !isSelf && (
                    <FlowStarter
                        agent={agent.ulid}
                        onboarding={onboarding}
                        offboarding={offboarding}
                    />
                )}
                {requests.length > 0 && (
                    <Requests requests={requests} canManage={canManage} />
                )}
                <ul className="divide-y rounded-lg border text-sm">
                    {records.credentials.length === 0 && (
                        <li className="p-3 text-muted-foreground">
                            Nothing on file yet.
                        </li>
                    )}
                    {records.credentials.map((c) => (
                        <Row
                            key={c.ulid}
                            c={c}
                            canManage={canManage}
                            canVerify={canVerify}
                        />
                    ))}
                </ul>
                {canManage && (
                    <form
                        className="flex flex-wrap gap-2 rounded-lg border p-4 text-sm"
                        onSubmit={(e) => {
                            e.preventDefault();
                            add.post(`/roster/${agent.ulid}/credentials`, {
                                ...opts,
                                onSuccess: () => add.reset(),
                            });
                        }}
                    >
                        <select
                            aria-label="Record type"
                            className={select}
                            value={add.data.kind}
                            onChange={(e) =>
                                add.setData('kind', e.target.value)
                            }
                        >
                            {Object.entries(kinds).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                        {add.data.kind === 'licence' && (
                            <Input
                                aria-label="State"
                                placeholder="State (TX)"
                                maxLength={2}
                                className="w-24"
                                value={add.data.state_code}
                                onChange={(e) =>
                                    add.setData('state_code', e.target.value)
                                }
                            />
                        )}
                        {add.data.kind !== 'eo' && (
                            <Input
                                aria-label="Number"
                                placeholder={
                                    add.data.kind === 'licence'
                                        ? 'Licence number'
                                        : 'Member ID'
                                }
                                className="w-40"
                                value={add.data.number}
                                onChange={(e) =>
                                    add.setData('number', e.target.value)
                                }
                            />
                        )}
                        {add.data.kind !== 'licence' && (
                            <Input
                                aria-label="Issuer"
                                placeholder={
                                    add.data.kind === 'eo'
                                        ? 'Insurer'
                                        : add.data.kind === 'mls'
                                          ? 'MLS name'
                                          : 'Association name'
                                }
                                className="w-48"
                                value={add.data.issuer}
                                onChange={(e) =>
                                    add.setData('issuer', e.target.value)
                                }
                            />
                        )}
                        {add.data.kind === 'eo' && (
                            <Input
                                aria-label="Coverage per claim ($)"
                                type="number"
                                placeholder="Coverage $"
                                className="w-32"
                                value={add.data.coverage}
                                onChange={(e) =>
                                    add.setData('coverage', e.target.value)
                                }
                            />
                        )}
                        <Input
                            aria-label="Expires on"
                            type="date"
                            className="w-40"
                            value={add.data.expires_on}
                            onChange={(e) =>
                                add.setData('expires_on', e.target.value)
                            }
                        />
                        <Button type="submit" size="sm">
                            Add
                        </Button>
                        {Object.values(add.errors)[0] && (
                            <p role="alert" className="w-full text-destructive">
                                {Object.values(add.errors)[0]}
                            </p>
                        )}
                    </form>
                )}
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">Documents</h2>
                    {records.documents.length === 0 ? (
                        <p className="text-muted-foreground">None yet.</p>
                    ) : (
                        records.documents.map((d) => (
                            <a
                                key={d.ulid}
                                className="underline"
                                href={`/roster/documents/${d.ulid}`}
                            >
                                {docKinds[d.kind] ?? d.kind}: {d.filename}
                            </a>
                        ))
                    )}
                    {canManage && (
                        <form
                            className="flex flex-wrap items-center gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                doc.post(`/roster/${agent.ulid}/documents`, {
                                    ...opts,
                                    forceFormData: true,
                                });
                            }}
                        >
                            <select
                                aria-label="Document type"
                                className={select}
                                value={doc.data.kind}
                                onChange={(e) =>
                                    doc.setData('kind', e.target.value)
                                }
                            >
                                {Object.entries(docKinds).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                            <input
                                aria-label="File"
                                type="file"
                                accept="application/pdf,image/*"
                                onChange={(e) =>
                                    doc.setData(
                                        'file',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={!doc.data.file}
                            >
                                Upload
                            </Button>
                            {Object.values(doc.errors)[0] && (
                                <p
                                    role="alert"
                                    className="w-full text-destructive"
                                >
                                    {Object.values(doc.errors)[0]}
                                </p>
                            )}
                        </form>
                    )}
                </section>
                {records.history.length > 0 && (
                    <details className="text-sm">
                        <summary className="cursor-pointer text-muted-foreground">
                            Earlier records ({records.history.length})
                        </summary>
                        <ul className="mt-2 grid gap-1">
                            {records.history.map((c) => (
                                <li
                                    key={c.ulid}
                                    className="text-muted-foreground"
                                >
                                    {title(c)} · expired/replaced{' '}
                                    {c.expires_on ?? ''}
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
                {isSelf && <UpdateForm />}
                <CePanel
                    agent={isSelf ? 'me' : agent.ulid}
                    ce={ce}
                    canManage={canManage}
                    canLog={isSelf || canManage}
                />
            </div>
        </>
    );
}

RosterAgent.layout = {
    breadcrumbs: [{ title: 'Roster and compliance', href: '/roster' }],
};

function FlowStarter({
    agent,
    onboarding,
    offboarding,
}: {
    agent: string;
    onboarding: Flow | null;
    offboarding: Flow | null;
}) {
    const off = useForm({
        kind: 'offboarding',
        leaving_on: new Date().toISOString().slice(0, 10),
        reason: '',
    });

    return (
        <section className="flex flex-wrap items-center gap-2 text-sm">
            {!onboarding && (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            `/roster/${agent}/flows`,
                            { kind: 'onboarding' },
                            { preserveScroll: true },
                        )
                    }
                >
                    Start onboarding
                </Button>
            )}
            {(!offboarding || offboarding.status === 'complete') && (
                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        off.post(`/roster/${agent}/flows`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    Leaving on{' '}
                    <Input
                        aria-label="Leaving on"
                        type="date"
                        className="w-40"
                        value={off.data.leaving_on}
                        onChange={(e) =>
                            off.setData('leaving_on', e.target.value)
                        }
                    />
                    <Input
                        aria-label="Reason for leaving"
                        placeholder="Reason"
                        className="w-48"
                        value={off.data.reason}
                        onChange={(e) => off.setData('reason', e.target.value)}
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="ghost"
                        disabled={off.data.reason.length < 3}
                    >
                        Start offboarding
                    </Button>
                </form>
            )}
        </section>
    );
}

function Requests({
    requests,
    canManage,
}: {
    requests: Props['requests'];
    canManage: boolean;
}) {
    const [note, setNote] = useState('');

    return (
        <section className="grid gap-2 rounded-lg border border-amber-300 p-4 text-sm">
            <h2 className="font-semibold">Updates waiting for approval</h2>
            {requests.map((r) => (
                <div key={r.ulid} className="flex flex-wrap items-center gap-2">
                    <span>
                        {kinds[r.kind]}
                        {r.data.state_code ? ` ${r.data.state_code}` : ''}
                        {r.data.number ? ` ${r.data.number}` : ''}
                        {r.data.issuer ? ` — ${r.data.issuer}` : ''}
                        {r.data.expires_on
                            ? `, expires ${r.data.expires_on}`
                            : ''}
                    </span>
                    {r.document && (
                        <a
                            className="underline"
                            href={`/roster/documents/${r.document}`}
                        >
                            Document
                        </a>
                    )}
                    {canManage && (
                        <>
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/roster/requests/${r.ulid}/decide`,
                                        { approve: true },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Approve
                            </Button>
                            <Input
                                aria-label="Reason if rejecting"
                                placeholder="Reason if rejecting"
                                className="w-48"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={note.length < 5}
                                onClick={() =>
                                    router.post(
                                        `/roster/requests/${r.ulid}/decide`,
                                        { approve: false, note },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Reject
                            </Button>
                        </>
                    )}
                </div>
            ))}
        </section>
    );
}

function UpdateForm() {
    const form = useForm<{
        kind: string;
        state_code: string;
        number: string;
        issuer: string;
        expires_on: string;
        file: File | null;
    }>({
        kind: 'licence',
        state_code: '',
        number: '',
        issuer: '',
        expires_on: '',
        file: null,
    });

    return (
        <form
            className="grid gap-2 rounded-lg border p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/roster/me/requests', {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <h2 className="font-semibold">Send an update</h2>
            <p className="text-muted-foreground">
                Renewed your licence or E&O? Send it here with the document; it
                takes effect when your brokerage approves.
            </p>
            <span className="flex flex-wrap gap-2">
                <select
                    aria-label="What you are updating"
                    className={select}
                    value={form.data.kind}
                    onChange={(e) => form.setData('kind', e.target.value)}
                >
                    {Object.entries(kinds).map(([k, v]) => (
                        <option key={k} value={k}>
                            {v}
                        </option>
                    ))}
                </select>
                {form.data.kind === 'licence' && (
                    <Input
                        aria-label="State"
                        placeholder="State"
                        maxLength={2}
                        className="w-20"
                        value={form.data.state_code}
                        onChange={(e) =>
                            form.setData('state_code', e.target.value)
                        }
                    />
                )}
                {form.data.kind !== 'eo' && (
                    <Input
                        aria-label="Number"
                        placeholder="Number"
                        className="w-36"
                        value={form.data.number}
                        onChange={(e) => form.setData('number', e.target.value)}
                    />
                )}
                {form.data.kind !== 'licence' && (
                    <Input
                        aria-label="Issuer"
                        placeholder={
                            form.data.kind === 'eo' ? 'Insurer' : 'Name'
                        }
                        className="w-40"
                        value={form.data.issuer}
                        onChange={(e) => form.setData('issuer', e.target.value)}
                    />
                )}
                <Input
                    aria-label="Expires on"
                    type="date"
                    className="w-40"
                    value={form.data.expires_on}
                    onChange={(e) => form.setData('expires_on', e.target.value)}
                />
                <input
                    aria-label="Document"
                    type="file"
                    accept="application/pdf,image/*"
                    onChange={(e) =>
                        form.setData('file', e.target.files?.[0] ?? null)
                    }
                />
                <Button type="submit" size="sm" disabled={!form.data.file}>
                    Send for approval
                </Button>
            </span>
            {Object.values(form.errors)[0] && (
                <p role="alert" className="text-destructive">
                    {Object.values(form.errors)[0]}
                </p>
            )}
        </form>
    );
}
