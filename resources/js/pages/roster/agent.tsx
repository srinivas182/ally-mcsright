import { Head, router, useForm } from '@inertiajs/react';
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

function Row({ c, canManage }: { c: Credential; canManage: boolean }) {
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
export default function RosterAgent({ agent, records, canManage }: Props) {
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
                <ul className="divide-y rounded-lg border text-sm">
                    {records.credentials.length === 0 && (
                        <li className="p-3 text-muted-foreground">
                            Nothing on file yet.
                        </li>
                    )}
                    {records.credentials.map((c) => (
                        <Row key={c.ulid} c={c} canManage={canManage} />
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
            </div>
        </>
    );
}

RosterAgent.layout = {
    breadcrumbs: [{ title: 'Roster and compliance', href: '/roster' }],
};
