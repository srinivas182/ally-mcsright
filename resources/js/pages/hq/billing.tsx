import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Plan = {
    ulid: string;
    key: string;
    name: string;
    description: string | null;
    list_cents: number;
    floor_cents: number;
    features: string[];
    sort: number;
    active: boolean;
};
type Pkg = {
    ulid: string;
    name: string;
    monthly_cents: number;
    yearly_cents: number;
    ailly_conversations: number;
    features: string[];
    active: boolean;
};
type Props = {
    packages: Pkg[];
    plans: Plan[];
    tiers: { min_paying_agents: number; percent: string }[];
    features: Record<string, string>;
};

/** HQ: agent plans (public list price and brokerage floor) and brokerage commission tiers. */
export default function HqBilling({ plans, tiers, features, packages }: Props) {
    const form = useForm({
        key: '',
        name: '',
        description: '',
        list: '',
        floor: '',
        features: [] as string[],
        sort: 0,
    });
    const pkg = useForm({
        name: '',
        monthly: '',
        yearly: '',
        ailly: '500',
        features: [] as string[],
    });
    const [editing, setEditing] = useState<string | null>(null);
    const [tierRows, setTierRows] = useState(
        tiers.length
            ? tiers.map((t) => ({ ...t, percent: String(t.percent) }))
            : [
                  { min_paying_agents: 1, percent: '20' },
                  { min_paying_agents: 25, percent: '25' },
                  { min_paying_agents: 100, percent: '30' },
              ],
    );

    const load = (p: Plan) => {
        setEditing(p.ulid);
        form.setData({
            key: p.key,
            name: p.name,
            description: p.description ?? '',
            list: String(p.list_cents / 100),
            floor: String(p.floor_cents / 100),
            features: p.features,
            sort: p.sort,
        });
    };

    return (
        <>
            <Head title="Pricing" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Agent plans and pricing"
                    description="List price is public. Brokerages charge between their floor and the list price. Increases reach brokerages after 60 days."
                />
                <ul className="grid gap-3 md:grid-cols-3">
                    {plans.map((p) => (
                        <li
                            key={p.ulid}
                            className="grid gap-1 rounded-lg border p-4 text-sm"
                        >
                            <span className="font-semibold">{p.name}</span>
                            <span>
                                List {usd(p.list_cents)} · floor{' '}
                                {usd(p.floor_cents)} / month
                            </span>
                            <span className="text-muted-foreground">
                                {p.features
                                    .map((f) => features[f] ?? f)
                                    .join(' · ')}
                            </span>
                            <Button
                                size="sm"
                                variant="outline"
                                className="justify-self-start"
                                onClick={() => load(p)}
                            >
                                Edit
                            </Button>
                        </li>
                    ))}
                </ul>
                <form
                    className="grid gap-2 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const done = {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                setEditing(null);
                            },
                        };
                        if (editing) {
                            form.put(`/hq/billing/plans/${editing}`, done);
                        } else {
                            form.post('/hq/billing/plans', done);
                        }
                    }}
                >
                    <p className="font-medium">
                        {editing ? 'Edit plan' : 'New plan'}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Input
                            aria-label="Key"
                            placeholder="pro"
                            className="w-32"
                            value={form.data.key}
                            onChange={(e) =>
                                form.setData('key', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Name"
                            placeholder="Pro"
                            className="w-48"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <Input
                            aria-label="List price per month ($)"
                            placeholder="List $/month"
                            type="number"
                            className="w-36"
                            value={form.data.list}
                            onChange={(e) =>
                                form.setData('list', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Brokerage floor per month ($)"
                            placeholder="Floor $/month"
                            type="number"
                            className="w-36"
                            value={form.data.floor}
                            onChange={(e) =>
                                form.setData('floor', e.target.value)
                            }
                        />
                    </div>
                    <Input
                        aria-label="Description"
                        placeholder="What agents get"
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                    />
                    <fieldset className="grid gap-1">
                        {Object.entries(features).map(([k, label]) => (
                            <label key={k} className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={form.data.features.includes(k)}
                                    onChange={(e) =>
                                        form.setData(
                                            'features',
                                            e.target.checked
                                                ? [...form.data.features, k]
                                                : form.data.features.filter(
                                                      (f) => f !== k,
                                                  ),
                                        )
                                    }
                                />
                                {label}
                            </label>
                        ))}
                    </fieldset>
                    {Object.values(form.errors).length > 0 && (
                        <p role="alert" className="text-destructive">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                    <Button
                        type="submit"
                        className="justify-self-start"
                        disabled={form.processing}
                    >
                        Save plan
                    </Button>
                </form>
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <p className="font-medium">
                        Brokerage commission tiers (by paying agents)
                    </p>
                    {tierRows.map((t, i) => (
                        <span key={i} className="flex items-center gap-2">
                            From
                            <Input
                                aria-label={`Tier ${i + 1} from`}
                                type="number"
                                className="w-24"
                                value={t.min_paying_agents}
                                onChange={(e) =>
                                    setTierRows(
                                        tierRows.map((x, j) =>
                                            j === i
                                                ? {
                                                      ...x,
                                                      min_paying_agents: Number(
                                                          e.target.value,
                                                      ),
                                                  }
                                                : x,
                                        ),
                                    )
                                }
                            />
                            paying agents:
                            <Input
                                aria-label={`Tier ${i + 1} percent`}
                                type="number"
                                className="w-20"
                                value={t.percent}
                                onChange={(e) =>
                                    setTierRows(
                                        tierRows.map((x, j) =>
                                            j === i
                                                ? {
                                                      ...x,
                                                      percent: e.target.value,
                                                  }
                                                : x,
                                        ),
                                    )
                                }
                            />
                            %
                        </span>
                    ))}
                    <Button
                        variant="outline"
                        className="justify-self-start"
                        onClick={() =>
                            router.post(
                                '/hq/billing/tiers',
                                { tiers: tierRows },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Save tiers
                    </Button>
                </section>
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <p className="font-medium">
                        Brokerage packages (the brokerage's flat fee)
                    </p>
                    {packages.map((p) => (
                        <span key={p.ulid}>
                            {p.name}: {usd(p.monthly_cents)}/month or{' '}
                            {usd(p.yearly_cents)}/year · AIlly{' '}
                            {p.ailly_conversations} conversations
                        </span>
                    ))}
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            pkg.post('/hq/billing/packages', {
                                preserveScroll: true,
                                onSuccess: () => pkg.reset(),
                            });
                        }}
                    >
                        <Input
                            aria-label="Package name"
                            placeholder="Brokerage"
                            className="w-40"
                            value={pkg.data.name}
                            onChange={(e) =>
                                pkg.setData('name', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Monthly ($)"
                            placeholder="$/month"
                            type="number"
                            className="w-28"
                            value={pkg.data.monthly}
                            onChange={(e) =>
                                pkg.setData('monthly', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Yearly ($)"
                            placeholder="$/year"
                            type="number"
                            className="w-28"
                            value={pkg.data.yearly}
                            onChange={(e) =>
                                pkg.setData('yearly', e.target.value)
                            }
                        />
                        <Input
                            aria-label="AIlly conversations a month"
                            type="number"
                            className="w-28"
                            value={pkg.data.ailly}
                            onChange={(e) =>
                                pkg.setData('ailly', e.target.value)
                            }
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={pkg.processing}
                        >
                            Add package
                        </Button>
                    </form>
                </section>
            </div>
        </>
    );
}

HqBilling.layout = { breadcrumbs: [{ title: 'Pricing', href: '/hq/billing' }] };
