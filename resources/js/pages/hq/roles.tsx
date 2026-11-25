import { Head, router, useForm } from '@inertiajs/react';
import { Lock, Plus, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { cn } from '@/lib/utils';

type RoleRow = {
    ulid: string;
    name: string;
    description: string | null;
    super: boolean;
    ready_made: boolean;
    permissions: string[];
};
type Props = {
    roles: RoleRow[];
    people: {
        ulid: string;
        name: string;
        email: string;
        role: string | null;
    }[];
    areas: { key: string; label: string }[];
    extras: { key: string; label: string }[];
    superOnly: string[];
};

type Level = 'none' | 'view' | 'manage';

const levelOf = (perms: string[], area: string): Level =>
    perms.includes(`hq.${area}.change`)
        ? 'manage'
        : perms.includes(`hq.${area}.see`)
          ? 'view'
          : 'none';

/**
 * HQ2: who in HQ can do what. Seven ready-made roles plus any HQ makes; each HQ person holds one role. Super admin
 * has everything and cannot be changed; a few things (money rails and keys, who can do what, taking a brokerage's
 * data out) only Super admin can do.
 */
export default function HqRoles({
    roles,
    people,
    areas,
    extras,
    superOnly,
}: Props) {
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <>
            <Head title="HQ roles" />
            <div className="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="HQ roles"
                    description="Who in HQ can see and change each part of HQ. Each person has one role. Pages someone cannot use are left out of their menu and refused if opened."
                    actions={
                        <Button onClick={() => setEditing('new')}>
                            <Plus aria-hidden="true" /> New role
                        </Button>
                    }
                />

                <section aria-labelledby="people" className="grid gap-3">
                    <h2 id="people" className="text-lg font-semibold">
                        People and their role
                    </h2>
                    <div className="overflow-x-auto rounded-xl border bg-card">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        Person
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Role
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {people.map((p) => (
                                    <tr key={p.ulid} className="border-t">
                                        <td className="px-4 py-3">
                                            <span className="block font-medium">
                                                {p.name}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {p.email}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <select
                                                aria-label={`Role for ${p.name}`}
                                                className="h-10 min-w-56 rounded-md border bg-background px-3"
                                                value={p.role ?? ''}
                                                onChange={(e) =>
                                                    router.post(
                                                        `/hq/roles/people/${p.ulid}`,
                                                        {
                                                            role: e.target
                                                                .value,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {p.role === null && (
                                                    <option value="">
                                                        No role yet (sees Today
                                                        only)
                                                    </option>
                                                )}
                                                {roles.map((r) => (
                                                    <option
                                                        key={r.ulid}
                                                        value={r.ulid}
                                                    >
                                                        {r.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Add people under HQ team, then give them a role here.
                        There is always at least one Super admin.
                    </p>
                </section>

                <section aria-labelledby="roles" className="grid gap-3">
                    <h2 id="roles" className="text-lg font-semibold">
                        Roles
                    </h2>
                    {editing === 'new' && (
                        <RoleEditor
                            areas={areas}
                            extras={extras}
                            superOnly={superOnly}
                            onDone={() => setEditing(null)}
                        />
                    )}
                    <div className="grid gap-3 md:grid-cols-2">
                        {roles.map((r) =>
                            editing === r.ulid ? (
                                <div key={r.ulid} className="md:col-span-2">
                                    <RoleEditor
                                        role={r}
                                        areas={areas}
                                        extras={extras}
                                        superOnly={superOnly}
                                        onDone={() => setEditing(null)}
                                    />
                                </div>
                            ) : (
                                <article
                                    key={r.ulid}
                                    className="grid content-start gap-2 rounded-xl border bg-card p-4"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        {r.super ? (
                                            <ShieldCheck
                                                aria-hidden="true"
                                                className="size-5 text-primary"
                                            />
                                        ) : null}
                                        <h3 className="font-semibold">
                                            {r.name}
                                        </h3>
                                        {r.ready_made ? (
                                            <Badge variant="outline">
                                                Ready-made
                                            </Badge>
                                        ) : (
                                            <Badge variant="secondary">
                                                Made by HQ
                                            </Badge>
                                        )}
                                        <span className="ml-auto text-sm text-muted-foreground">
                                            {
                                                people.filter(
                                                    (p) => p.role === r.ulid,
                                                ).length
                                            }{' '}
                                            {people.filter(
                                                (p) => p.role === r.ulid,
                                            ).length === 1
                                                ? 'person'
                                                : 'people'}
                                        </span>
                                    </div>
                                    {r.description && (
                                        <p className="text-sm text-muted-foreground">
                                            {r.description}
                                        </p>
                                    )}
                                    <ul className="flex flex-wrap gap-1.5 text-xs">
                                        {areas.map((a) => {
                                            const level = r.super
                                                ? 'manage'
                                                : levelOf(r.permissions, a.key);

                                            return level === 'none' ? null : (
                                                <li
                                                    key={a.key}
                                                    className={cn(
                                                        'rounded-full px-2 py-0.5',
                                                        level === 'manage'
                                                            ? 'bg-primary/10 text-primary'
                                                            : 'bg-muted text-muted-foreground',
                                                    )}
                                                >
                                                    {a.label.split(':')[0]}
                                                    {level === 'view'
                                                        ? ' (see)'
                                                        : ''}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                    <div>
                                        {r.super ? (
                                            <span className="inline-flex items-center gap-1 text-sm text-muted-foreground">
                                                <Lock
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                                Cannot be changed
                                            </span>
                                        ) : (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setEditing(r.ulid)
                                                }
                                            >
                                                Change what it can do
                                            </Button>
                                        )}
                                    </div>
                                </article>
                            ),
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}

function RoleEditor({
    role,
    areas,
    extras,
    superOnly,
    onDone,
}: {
    role?: RoleRow;
    areas: Props['areas'];
    extras: Props['extras'];
    superOnly: string[];
    onDone: () => void;
}) {
    const form = useForm({
        name: role?.name ?? '',
        description: role?.description ?? '',
        permissions: role?.permissions ?? ['hq.today.see'],
    });
    const setLevel = (area: string, level: Level) => {
        const rest = form.data.permissions.filter(
            (p) => p !== `hq.${area}.see` && p !== `hq.${area}.change`,
        );
        form.setData(
            'permissions',
            level === 'none'
                ? rest
                : level === 'view'
                  ? [...rest, `hq.${area}.see`]
                  : [...rest, `hq.${area}.see`, `hq.${area}.change`],
        );
    };

    return (
        <form
            className="grid gap-4 rounded-xl border border-primary/30 bg-card p-5"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(role ? `/hq/roles/${role.ulid}` : '/hq/roles', {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="grid gap-1 text-sm font-medium">
                    Role name
                    <Input
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        required
                    />
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    What this role is for
                    <Input
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                    />
                </label>
            </div>
            <fieldset className="grid gap-2">
                <legend className="mb-1 font-medium">What it can use</legend>
                {areas.map((a) => {
                    const level = levelOf(form.data.permissions, a.key);
                    const manageLocked = superOnly.includes(
                        `hq.${a.key}.change`,
                    );

                    return (
                        <div
                            key={a.key}
                            className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border px-3 py-2 text-sm"
                        >
                            <span className="min-w-64 flex-1">{a.label}</span>
                            {(['none', 'view', 'manage'] as Level[]).map(
                                (l) => (
                                    <label
                                        key={l}
                                        className="flex items-center gap-1.5"
                                    >
                                        <input
                                            type="radio"
                                            name={`level-${a.key}`}
                                            className="size-4"
                                            checked={level === l}
                                            disabled={
                                                l === 'manage' && manageLocked
                                            }
                                            onChange={() => setLevel(a.key, l)}
                                        />
                                        {l === 'none'
                                            ? 'No access'
                                            : l === 'view'
                                              ? 'See'
                                              : 'See and change'}
                                    </label>
                                ),
                            )}
                            {manageLocked && (
                                <span className="text-xs text-muted-foreground">
                                    Changing this: Super admin only
                                </span>
                            )}
                        </div>
                    );
                })}
                {extras.map((x) => {
                    const locked = superOnly.includes(x.key);

                    return (
                        <label
                            key={x.key}
                            className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                className="size-4"
                                disabled={locked}
                                checked={form.data.permissions.includes(x.key)}
                                onChange={(e) =>
                                    form.setData(
                                        'permissions',
                                        e.target.checked
                                            ? [...form.data.permissions, x.key]
                                            : form.data.permissions.filter(
                                                  (p) => p !== x.key,
                                              ),
                                    )
                                }
                            />
                            {x.label}
                            {locked && (
                                <span className="ml-auto text-xs text-muted-foreground">
                                    Super admin only
                                </span>
                            )}
                        </label>
                    );
                })}
            </fieldset>
            {Object.values(form.errors)[0] && (
                <p role="alert" className="text-destructive">
                    {Object.values(form.errors)[0]}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={form.processing}>
                    Save role
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                {role && !role.ready_made && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="ml-auto text-destructive"
                        onClick={() =>
                            router.post(
                                `/hq/roles/${role.ulid}/delete`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Remove this role
                    </Button>
                )}
            </div>
        </form>
    );
}

HqRoles.layout = {
    breadcrumbs: [{ title: 'HQ roles', href: '/hq/roles' }],
};
