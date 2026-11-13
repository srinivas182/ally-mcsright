import { Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Issue = { level: string; text: string };
type Props = {
    dashboard: {
        agents: { ulid: string; name: string; issues: Issue[] }[];
        counts: Record<string, number>;
    };
    settings: { guard_licence: boolean; eo_required: boolean };
    isOwner: boolean;
};

const tiles: [string, string][] = [
    ['expired', 'Expired'],
    ['within_30', 'Within 30 days'],
    ['within_60', 'Within 60 days'],
    ['within_90', 'Within 90 days'],
    ['missing', 'Missing records'],
    ['unverified', 'Unverified licences'],
];
const tone: Record<string, 'destructive' | 'outline' | 'secondary'> = {
    expired: 'destructive',
    within_30: 'destructive',
    missing: 'destructive',
    within_60: 'outline',
    within_90: 'outline',
    unverified: 'secondary',
};

/** Licences, E&O, memberships and documents for every agent, and the licence guard. */
export default function RosterIndex({ dashboard, settings, isOwner }: Props) {
    const save = (patch: Partial<Props['settings']>) =>
        router.post(
            '/roster/settings',
            { ...settings, ...patch },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Roster and compliance" />
            <div className="flex max-w-5xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Roster and compliance"
                    description="Every agent's licences, E&O, memberships and documents — and what needs attention."
                />
                <section
                    className={`grid gap-2 rounded-lg border p-4 text-sm ${settings.guard_licence ? '' : 'border-amber-300 bg-amber-50 text-amber-900'}`}
                >
                    <p className="font-medium">
                        {settings.guard_licence
                            ? "Licence guard is on: deals go under contract only with an active licence in the deal's state" +
                              (settings.eo_required ? ' and active E&O.' : '.')
                            : "Licence guard is off. Turn it on once your agents' licences are loaded, so no deal goes under contract with an expired licence."}
                    </p>
                    {isOwner && (
                        <span className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                variant={
                                    settings.guard_licence
                                        ? 'outline'
                                        : 'default'
                                }
                                onClick={() =>
                                    save({
                                        guard_licence: !settings.guard_licence,
                                    })
                                }
                            >
                                {settings.guard_licence
                                    ? 'Turn the guard off'
                                    : 'Turn the guard on'}
                            </Button>
                            <label className="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={settings.eo_required}
                                    onChange={(e) =>
                                        save({ eo_required: e.target.checked })
                                    }
                                />{' '}
                                Require active E&O
                            </label>
                        </span>
                    )}
                </section>
                <section className="grid grid-cols-2 gap-2 sm:grid-cols-6">
                    {tiles.map(([k, label]) => (
                        <div key={k} className="rounded-lg border p-3 text-sm">
                            <p className="text-muted-foreground">{label}</p>
                            <p className="text-xl font-semibold">
                                {dashboard.counts[k] ?? 0}
                            </p>
                        </div>
                    ))}
                </section>
                <ul className="divide-y rounded-lg border text-sm">
                    {dashboard.agents.length === 0 && (
                        <li className="p-3 text-muted-foreground">
                            No active agents yet.
                        </li>
                    )}
                    {dashboard.agents.map((a) => (
                        <li
                            key={a.ulid}
                            className="flex flex-wrap items-center justify-between gap-2 p-3"
                        >
                            <Link
                                className="font-medium hover:underline"
                                href={`/roster/${a.ulid}`}
                            >
                                {a.name}
                            </Link>
                            <span className="flex flex-wrap gap-1">
                                {a.issues.length === 0 ? (
                                    <Badge variant="secondary">
                                        All current
                                    </Badge>
                                ) : (
                                    a.issues.map((i, n) => (
                                        <Badge key={n} variant={tone[i.level]}>
                                            {i.text}
                                        </Badge>
                                    ))
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

RosterIndex.layout = {
    breadcrumbs: [{ title: 'Roster and compliance', href: '/roster' }],
};
