import { Head, router, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Person = { ulid: string; name: string; offices: string[] };
type Props = {
    offices: { ulid: string; name: string; primary: boolean; people: number }[];
    teams: {
        ulid: string;
        name: string;
        split: string | null;
        office: string | null;
        leader: string | null;
        members: { ulid: string; name: string }[];
    }[];
    people: Person[];
};

export default function Organisation({ offices, teams, people }: Props) {
    const office = useForm({ name: '', city: '', state_code: '' });
    const team = useForm({
        name: '',
        office: '',
        leader: '',
        split_percent: '',
    });
    const member = (data: Record<string, string | boolean>) =>
        router.post('/settings/membership', data, { preserveScroll: true });

    return (
        <>
            <Head title="Offices and teams" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Offices and teams"
                    description="Used for role scopes: 'their office' and 'their team'."
                />
                <section className="grid gap-2">
                    <h2 className="font-semibold">Offices</h2>
                    <ul className="divide-y rounded-lg border text-sm">
                        {offices.map((o) => (
                            <li
                                key={o.ulid}
                                className="flex items-center justify-between p-3"
                            >
                                <span>
                                    {o.name}
                                    {o.primary ? ' · main office' : ''}
                                </span>
                                <span className="text-muted-foreground">
                                    {o.people} people
                                </span>
                            </li>
                        ))}
                    </ul>
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            office.post('/settings/offices', {
                                preserveScroll: true,
                                onSuccess: () => office.reset(),
                            });
                        }}
                    >
                        <Input
                            aria-label="Office name"
                            placeholder="Downtown office"
                            className="max-w-xs"
                            value={office.data.name}
                            onChange={(e) =>
                                office.setData('name', e.target.value)
                            }
                        />
                        <Input
                            aria-label="City"
                            placeholder="Jackson"
                            className="max-w-40"
                            value={office.data.city}
                            onChange={(e) =>
                                office.setData('city', e.target.value)
                            }
                        />
                        <Input
                            aria-label="State"
                            placeholder="MS"
                            className="w-16"
                            maxLength={2}
                            value={office.data.state_code}
                            onChange={(e) =>
                                office.setData(
                                    'state_code',
                                    e.target.value.toUpperCase(),
                                )
                            }
                        />
                        <Button type="submit" variant="outline">
                            Add office
                        </Button>
                    </form>
                </section>
                <section className="grid gap-2">
                    <h2 className="font-semibold">Teams</h2>
                    <ul className="grid gap-2 md:grid-cols-2">
                        {teams.map((t) => (
                            <li
                                key={t.ulid}
                                className="grid gap-1 rounded-lg border p-3 text-sm"
                            >
                                <span className="font-medium">{t.name}</span>
                                <span className="text-muted-foreground">
                                    {[
                                        t.office,
                                        t.leader ? `Lead: ${t.leader}` : null,
                                        t.split
                                            ? `Team split ${Number(t.split)}%`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                                <span>
                                    {t.members.map((m) => m.name).join(', ') ||
                                        'No members yet'}
                                </span>
                                <select
                                    aria-label={`Add to ${t.name}`}
                                    className="rounded border bg-background px-2 py-1"
                                    value=""
                                    onChange={(e) =>
                                        e.target.value &&
                                        member({
                                            user: e.target.value,
                                            team: t.ulid,
                                        })
                                    }
                                >
                                    <option value="">Add a member…</option>
                                    {people
                                        .filter(
                                            (p) =>
                                                !t.members.some(
                                                    (m) => m.ulid === p.ulid,
                                                ),
                                        )
                                        .map((p) => (
                                            <option key={p.ulid} value={p.ulid}>
                                                {p.name}
                                            </option>
                                        ))}
                                </select>
                            </li>
                        ))}
                    </ul>
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            team.post('/settings/teams', {
                                preserveScroll: true,
                                onSuccess: () => team.reset(),
                            });
                        }}
                    >
                        <Input
                            aria-label="Team name"
                            placeholder="Smith Team"
                            className="max-w-xs"
                            value={team.data.name}
                            onChange={(e) =>
                                team.setData('name', e.target.value)
                            }
                        />
                        <select
                            aria-label="Office"
                            className="rounded border bg-background px-2 py-1"
                            value={team.data.office}
                            onChange={(e) =>
                                team.setData('office', e.target.value)
                            }
                        >
                            <option value="">No office</option>
                            {offices.map((o) => (
                                <option key={o.ulid} value={o.ulid}>
                                    {o.name}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Team lead"
                            className="rounded border bg-background px-2 py-1"
                            value={team.data.leader}
                            onChange={(e) =>
                                team.setData('leader', e.target.value)
                            }
                        >
                            <option value="">No lead</option>
                            {people.map((p) => (
                                <option key={p.ulid} value={p.ulid}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                        <Input
                            aria-label="Team split percent"
                            placeholder="Team split %"
                            type="number"
                            min={0}
                            max={100}
                            className="w-32"
                            value={team.data.split_percent}
                            onChange={(e) =>
                                team.setData('split_percent', e.target.value)
                            }
                        />
                        <Button type="submit" variant="outline">
                            Add team
                        </Button>
                    </form>
                </section>
                <section className="grid gap-2">
                    <h2 className="font-semibold">Who is in which office</h2>
                    <ul className="divide-y rounded-lg border text-sm">
                        {people.map((p) => (
                            <li
                                key={p.ulid}
                                className="flex flex-wrap items-center justify-between gap-2 p-2"
                            >
                                <span>{p.name}</span>
                                <span className="flex flex-wrap gap-2">
                                    {offices.map((o) => (
                                        <label
                                            key={o.ulid}
                                            className="flex items-center gap-1"
                                        >
                                            <input
                                                type="checkbox"
                                                className="size-4"
                                                checked={p.offices.includes(
                                                    o.ulid,
                                                )}
                                                onChange={(e) =>
                                                    member({
                                                        user: p.ulid,
                                                        office: o.ulid,
                                                        remove: !e.target
                                                            .checked,
                                                    })
                                                }
                                            />
                                            {o.name}
                                        </label>
                                    ))}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

Organisation.layout = {
    breadcrumbs: [
        { title: 'Offices and teams', href: '/settings/organisation' },
    ],
};
