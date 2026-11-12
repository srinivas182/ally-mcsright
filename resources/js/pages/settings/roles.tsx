import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { postJson } from '@/lib/http';

type Perm = {
    key: string;
    area: string;
    label: string;
    scoped: boolean;
    sensitive: boolean;
};
type Role = {
    ulid: string;
    name: string;
    description: string | null;
    locked: boolean;
    template: string | null;
    members: number;
    permissions: Record<string, string | null>;
    preview: string[];
};
type Props = {
    roles: Role[];
    catalogue: { area: string; permissions: Perm[] }[];
};

const scopes: [string, string][] = [
    ['own', 'their own records'],
    ['team', 'their team'],
    ['office', 'their office'],
    ['tenant', 'everyone'],
];

function Editor({
    role,
    catalogue,
    onClose,
}: {
    role: Role | null;
    catalogue: Props['catalogue'];
    onClose: () => void;
}) {
    const [name, setName] = useState(role?.name ?? '');
    const [description, setDescription] = useState(role?.description ?? '');
    const [perms, setPerms] = useState<Record<string, string | null>>({
        ...role?.permissions,
    });
    const [preview, setPreview] = useState<{
        lines: string[];
        conflicts: string[];
    }>({ lines: role?.preview ?? [], conflicts: [] });
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const t = setTimeout(() => {
            void postJson<{ lines: string[]; conflicts: string[] }>(
                '/settings/roles/preview',
                { permissions: perms },
            )
                .then(setPreview)
                .catch(() => undefined);
        }, 250);
        return () => clearTimeout(t);
    }, [perms]);

    const toggle = (p: Perm, on: boolean) => {
        const next = { ...perms };
        if (on) {
            next[p.key] = p.scoped ? 'own' : null;
        } else {
            delete next[p.key];
        }
        setPerms(next);
    };

    return (
        <section className="grid gap-4 rounded-lg border p-4 lg:grid-cols-[1fr_320px]">
            <div className="grid content-start gap-3">
                <Input
                    aria-label="Role name"
                    placeholder="Role name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                />
                <Input
                    aria-label="Description"
                    placeholder="What is this role for?"
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                />
                {catalogue.map((group) => (
                    <fieldset key={group.area} className="grid gap-1">
                        <legend className="mb-1 text-sm font-medium">
                            {group.area}
                        </legend>
                        {group.permissions.map((p) => {
                            const on = p.key in perms;
                            return (
                                <div
                                    key={p.key}
                                    className="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <label className="flex flex-1 items-center gap-2">
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            checked={on}
                                            onChange={(e) =>
                                                toggle(p, e.target.checked)
                                            }
                                        />
                                        <span>
                                            {p.label}
                                            {p.sensitive && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2"
                                                >
                                                    Sensitive
                                                </Badge>
                                            )}
                                        </span>
                                    </label>
                                    {on && p.scoped && (
                                        <select
                                            aria-label={`Scope for ${p.label}`}
                                            className="rounded border bg-background px-2 py-1"
                                            value={perms[p.key] ?? 'own'}
                                            onChange={(e) =>
                                                setPerms({
                                                    ...perms,
                                                    [p.key]: e.target.value,
                                                })
                                            }
                                        >
                                            {scopes.map(([v, l]) => (
                                                <option key={v} value={v}>
                                                    for {l}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                </div>
                            );
                        })}
                    </fieldset>
                ))}
            </div>
            <aside className="grid content-start gap-2 rounded-md bg-muted/40 p-3 text-sm">
                <p className="font-medium">Preview: someone with this role</p>
                <ul className="grid gap-1">
                    {preview.lines.map((l) => (
                        <li
                            key={l}
                            className={
                                l.startsWith('Cannot')
                                    ? 'text-muted-foreground'
                                    : ''
                            }
                        >
                            {l}
                        </li>
                    ))}
                </ul>
                {preview.conflicts.map((c) => (
                    <p key={c} role="alert" className="text-destructive">
                        {c}
                    </p>
                ))}
                {error && (
                    <p role="alert" className="text-destructive">
                        {error}
                    </p>
                )}
                <div className="mt-2 flex gap-2">
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        disabled={
                            preview.conflicts.length > 0 || name.trim() === ''
                        }
                        onClick={() => {
                            const data = {
                                name,
                                description,
                                permissions: perms,
                            };
                            const opts = {
                                preserveScroll: true,
                                onSuccess: onClose,
                                onError: (e: Record<string, string>) =>
                                    setError(Object.values(e)[0] ?? null),
                            };
                            if (role && role.ulid !== '') {
                                router.put(
                                    `/settings/roles/${role.ulid}`,
                                    data,
                                    opts,
                                );
                            } else {
                                router.post('/settings/roles', data, opts);
                            }
                        }}
                    >
                        Save role
                    </Button>
                </div>
            </aside>
        </section>
    );
}

export default function Roles({ roles, catalogue }: Props) {
    const [editing, setEditing] = useState<Role | null | undefined>(undefined);

    return (
        <>
            <Head title="Roles" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Roles"
                    description="What each role can do, in plain English. People without a role keep the access of their type."
                    actions={
                        <Button onClick={() => setEditing(null)}>
                            New role
                        </Button>
                    }
                />
                {editing !== undefined && (
                    <Editor
                        key={editing?.ulid ?? 'new'}
                        role={editing}
                        catalogue={catalogue}
                        onClose={() => setEditing(undefined)}
                    />
                )}
                <ul className="grid gap-3 md:grid-cols-2">
                    {roles.map((r) => (
                        <li
                            key={r.ulid}
                            className="grid content-start gap-2 rounded-lg border p-4 text-sm"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <span className="font-medium">{r.name}</span>
                                <span className="flex gap-1">
                                    {r.locked && (
                                        <Badge variant="secondary">
                                            Locked
                                        </Badge>
                                    )}
                                    <Badge variant="outline">
                                        {r.members}{' '}
                                        {r.members === 1 ? 'person' : 'people'}
                                    </Badge>
                                </span>
                            </div>
                            {r.description && (
                                <p className="text-muted-foreground">
                                    {r.description}
                                </p>
                            )}
                            <ul className="list-disc pl-4 text-muted-foreground">
                                {r.preview.slice(0, 5).map((l) => (
                                    <li key={l}>{l}</li>
                                ))}
                            </ul>
                            {!r.locked && (
                                <span className="flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setEditing(r)}
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            setEditing({
                                                ...r,
                                                ulid: '',
                                                name: `${r.name} (copy)`,
                                                members: 0,
                                            } as Role)
                                        }
                                    >
                                        Copy
                                    </Button>
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

Roles.layout = { breadcrumbs: [{ title: 'Roles', href: '/settings/roles' }] };
