import { Head, router } from '@inertiajs/react';

import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Person = {
    name: string;
    email: string;
    phone: string | null;
    role: string;
    offices: string[];
    teams: string[];
    licences: string[];
};

/** Everyone in the brokerage, searchable. */
export default function Directory({
    q,
    people,
}: {
    q: string;
    people: Person[];
}) {
    return (
        <>
            <Head title="Directory" />
            <div className="flex max-w-4xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Directory"
                    description="Find anyone in the brokerage."
                />
                <Input
                    aria-label="Search people"
                    type="search"
                    placeholder="Search by name or email"
                    className="max-w-sm"
                    defaultValue={q}
                    onChange={(e) =>
                        router.get(
                            '/directory',
                            { q: e.target.value },
                            { preserveState: true, replace: true },
                        )
                    }
                />
                <ul className="divide-y rounded-lg border text-sm">
                    {people.length === 0 && (
                        <li className="p-3 text-muted-foreground">
                            Nobody matches.
                        </li>
                    )}
                    {people.map((p) => (
                        <li key={p.email} className="grid gap-0.5 p-3">
                            <span className="font-medium">
                                {p.name}{' '}
                                <span className="text-xs text-muted-foreground capitalize">
                                    {p.role}
                                </span>
                            </span>
                            <span>
                                <a
                                    className="underline"
                                    href={`mailto:${p.email}`}
                                >
                                    {p.email}
                                </a>
                                {p.phone ? (
                                    <>
                                        {' '}
                                        ·{' '}
                                        <a
                                            className="underline"
                                            href={`tel:${p.phone}`}
                                        >
                                            {p.phone}
                                        </a>
                                    </>
                                ) : null}
                            </span>
                            {(p.offices.length > 0 || p.teams.length > 0) && (
                                <span className="text-muted-foreground">
                                    {[...p.offices, ...p.teams].join(' · ')}
                                </span>
                            )}
                            {p.licences.length > 0 && (
                                <span className="text-muted-foreground">
                                    Licence {p.licences.join(', ')}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

Directory.layout = {
    breadcrumbs: [{ title: 'Directory', href: '/directory' }],
};
