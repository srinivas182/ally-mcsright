import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';

type Agent = {
    id: number;
    name: string;
    mls_id: string;
    office: string | null;
    sides: number;
    list_sides: number;
    buyer_sides: number;
    volume_cents: number;
    trend: number | null;
    sides_before: number;
    active_listings: number;
    moved_on: string | null;
    previous_office: string | null;
    recruit: string | null;
};
type Filters = {
    q?: string;
    min_sides?: string;
    moved?: string;
    sort?: string;
};
type Settings = {
    own_office_ids: string[];
    commission_rate: number;
    compare_plan_id: number | null;
    plans: { id: number; name: string }[];
};

const money = (cents: number) =>
    (cents / 100).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    });

/** The MLS agent database (RC1): producing agents outside the brokerage, from licensed MLS data. */
export default function Agents({
    licensed,
    filters,
    agents,
    settings,
}: {
    licensed: boolean;
    filters: Filters;
    agents: {
        data: Agent[];
        current_page: number;
        last_page: number;
        total: number;
    };
    settings: Settings | null;
}) {
    const [f, setF] = useState<Filters>({
        q: filters.q ?? '',
        min_sides: filters.min_sides ?? '',
        moved: filters.moved ?? '',
        sort: filters.sort ?? 'sides',
    });
    const go = (next: Filters, page = 1) =>
        router.get(
            '/recruiting/agents',
            { ...next, page },
            { preserveState: true, preserveScroll: true },
        );
    const config = useForm({
        own_office_ids: (settings?.own_office_ids ?? []).join(', '),
        commission_rate: String(
            ((settings?.commission_rate ?? 0.025) * 100).toFixed(2),
        ),
        compare_plan_id: settings?.compare_plan_id
            ? String(settings.compare_plan_id)
            : '',
    });

    return (
        <>
            <Head title="Agent database" />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title="Agent database"
                    description="Agents outside your brokerage, with their last 12 months from the MLS."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/recruiting">Back to recruiting</Link>
                        </Button>
                    }
                />

                {!licensed ? (
                    <EmptyState
                        title="Needs an agent production licence"
                        description="Agent production comes from your MLS, under a licence that allows it. Ask RightAlly to add “agent production” to your MLS licence; the database fills in the next morning."
                    />
                ) : (
                    <>
                        <form
                            className="flex flex-wrap items-end gap-3 rounded-lg border p-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                go(f);
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Name, office or MLS ID
                                <Input
                                    className="w-56"
                                    value={f.q}
                                    onChange={(e) =>
                                        setF({ ...f, q: e.target.value })
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                At least sides
                                <Input
                                    className="w-24"
                                    type="number"
                                    min={0}
                                    value={f.min_sides}
                                    onChange={(e) =>
                                        setF({
                                            ...f,
                                            min_sides: e.target.value,
                                        })
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Changed brokerage
                                <select
                                    className="h-10 rounded-md border bg-background px-3"
                                    value={f.moved}
                                    onChange={(e) =>
                                        setF({ ...f, moved: e.target.value })
                                    }
                                >
                                    <option value="">Any time</option>
                                    <option value="30">
                                        In the last 30 days
                                    </option>
                                    <option value="90">
                                        In the last 90 days
                                    </option>
                                    <option value="365">
                                        In the last year
                                    </option>
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Sort by
                                <select
                                    className="h-10 rounded-md border bg-background px-3"
                                    value={f.sort}
                                    onChange={(e) =>
                                        setF({ ...f, sort: e.target.value })
                                    }
                                >
                                    <option value="sides">Most sides</option>
                                    <option value="volume">Most volume</option>
                                    <option value="trend">
                                        Growing fastest
                                    </option>
                                    <option value="moved">
                                        Changed brokerage recently
                                    </option>
                                </select>
                            </label>
                            <Button>Show</Button>
                        </form>

                        {agents.data.length === 0 ? (
                            <EmptyState
                                title="No agents match"
                                description="Try fewer filters."
                            />
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground">
                                        <tr>
                                            <th className="p-3 font-normal">
                                                Agent
                                            </th>
                                            <th className="p-3 font-normal">
                                                Closed sides (12 months)
                                            </th>
                                            <th className="p-3 font-normal">
                                                Volume
                                            </th>
                                            <th className="p-3 font-normal">
                                                Against the year before
                                            </th>
                                            <th className="p-3 font-normal">
                                                <span className="sr-only">
                                                    Action
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {agents.data.map((a) => (
                                            <tr
                                                key={a.id}
                                                className="border-t align-top"
                                            >
                                                <td className="p-3">
                                                    <strong className="text-base">
                                                        {a.name}
                                                    </strong>
                                                    <div className="text-muted-foreground">
                                                        {a.office ??
                                                            'Office not known'}
                                                    </div>
                                                    {a.moved_on && (
                                                        <Badge
                                                            variant="outline"
                                                            className="mt-1"
                                                        >
                                                            Moved {a.moved_on}
                                                            {a.previous_office &&
                                                                ` from ${a.previous_office}`}
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="p-3">
                                                    <strong>{a.sides}</strong>
                                                    <div className="text-muted-foreground">
                                                        {a.list_sides} listing ·{' '}
                                                        {a.buyer_sides} buyer
                                                        {a.active_listings >
                                                            0 &&
                                                            ` · ${a.active_listings} active`}
                                                    </div>
                                                </td>
                                                <td className="p-3">
                                                    {money(a.volume_cents)}
                                                </td>
                                                <td className="p-3">
                                                    {a.trend === null ? (
                                                        <span className="text-muted-foreground">
                                                            New this year
                                                        </span>
                                                    ) : (
                                                        <span
                                                            className={
                                                                a.trend >= 0
                                                                    ? 'text-emerald-700 dark:text-emerald-300'
                                                                    : 'text-amber-700 dark:text-amber-300'
                                                            }
                                                        >
                                                            {a.trend >= 0
                                                                ? '▲'
                                                                : '▼'}{' '}
                                                            {Math.abs(a.trend)}%
                                                            ({a.sides_before}{' '}
                                                            before)
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="p-3 text-right">
                                                    {a.recruit ? (
                                                        <Button
                                                            variant="outline"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/recruiting/${a.recruit}`}
                                                            >
                                                                Open
                                                            </Link>
                                                        </Button>
                                                    ) : (
                                                        <Button
                                                            onClick={() =>
                                                                router.post(
                                                                    `/recruiting/agents/${a.id}/recruit`,
                                                                )
                                                            }
                                                        >
                                                            Recruit
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <Pagination
                            page={agents.current_page}
                            lastPage={agents.last_page}
                            total={agents.total}
                            onPageChange={(p) => go(f, p)}
                        />
                    </>
                )}

                {settings && (
                    <section
                        className="grid gap-3 rounded-lg border p-4 text-sm"
                        aria-labelledby="settings"
                    >
                        <h2 id="settings" className="text-base font-semibold">
                            For the broker
                        </h2>
                        <form
                            className="flex flex-wrap items-end gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                config.post('/recruiting/settings', {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <label className="grid gap-1">
                                Your MLS office IDs (left out of the list)
                                <Input
                                    className="w-64"
                                    value={config.data.own_office_ids}
                                    onChange={(e) =>
                                        config.setData(
                                            'own_office_ids',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1">
                                Commission, % of price
                                <Input
                                    className="w-24"
                                    type="number"
                                    step="0.05"
                                    min={0.5}
                                    max={10}
                                    value={config.data.commission_rate}
                                    onChange={(e) =>
                                        config.setData(
                                            'commission_rate',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1">
                                Plan to show recruits
                                <select
                                    className="h-10 rounded-md border bg-background px-3"
                                    value={config.data.compare_plan_id}
                                    onChange={(e) =>
                                        config.setData(
                                            'compare_plan_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">Your default plan</option>
                                    {settings.plans.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <Button
                                variant="outline"
                                disabled={config.processing}
                            >
                                Save
                            </Button>
                        </form>
                        {Object.values(config.errors).map((m) => (
                            <p key={m} className="text-red-700">
                                {m}
                            </p>
                        ))}
                    </section>
                )}
            </div>
        </>
    );
}
