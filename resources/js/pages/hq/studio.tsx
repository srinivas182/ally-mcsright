import { Head, Link, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Allowances = {
    posts: number;
    reels: number;
    tours: number;
    enhance: number;
    voice: number;
    features: string[];
};

type Pkg = {
    key: string;
    name: string;
    price_cents: number | null;
    allowances: Allowances;
    cost_cents: number;
    margin_pct: number | null;
};

const money = (cents: number | null) =>
    cents === null ? 'not priced' : `$${(cents / 100).toFixed(2)}`;

function PackageCard({
    pkg,
    features,
}: {
    pkg: Pkg;
    features: Record<string, string>;
}) {
    const form = useForm({ ...pkg.allowances });
    const fields = [
        ['posts', 'Designed posts'],
        ['reels', 'Reels and Shorts'],
        ['tours', 'YouTube tours'],
        ['enhance', 'Photo enhancements'],
        ['voice', 'Voice narrations'],
    ] as const;

    return (
        <form
            className="grid gap-3 rounded-lg border p-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/hq/studio/packages/${pkg.key}`, {
                    preserveScroll: true,
                });
            }}
        >
            <h2 className="font-medium">
                {pkg.name}{' '}
                <Badge
                    variant={
                        pkg.margin_pct !== null && pkg.margin_pct < 40
                            ? 'destructive'
                            : 'outline'
                    }
                >
                    {money(pkg.price_cents)} a month · full use costs{' '}
                    {money(pkg.cost_cents)}
                    {pkg.margin_pct !== null && ` · ${pkg.margin_pct}% margin`}
                </Badge>
            </h2>
            <div className="grid gap-3 sm:grid-cols-5">
                {fields.map(([k, label]) => (
                    <label key={k} className="grid gap-1 text-sm">
                        {label}
                        <Input
                            type="number"
                            min={0}
                            value={form.data[k]}
                            onChange={(e) =>
                                form.setData(k, Number(e.target.value))
                            }
                        />
                    </label>
                ))}
            </div>
            <fieldset className="flex flex-wrap gap-4 text-sm">
                {Object.entries(features).map(([k, l]) => (
                    <label key={k} className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-5"
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
                        {l}
                    </label>
                ))}
            </fieldset>
            <div>
                <Button type="submit" disabled={form.processing}>
                    Save allowances
                </Button>
            </div>
        </form>
    );
}

/**
 * HQ → Ally Studio (specs/grow/13): allowances and features per package, with price, the cost of a full month's use
 * and the margin; and each extra item's credits, price, cost and margin.
 */
export default function HqStudio({
    packages,
    items,
    features,
}: {
    packages: Pkg[];
    items: {
        item: string;
        label: string;
        credits: number;
        price_cents: number;
        cost_cents: number;
        margin_pct: number | null;
    }[];
    features: Record<string, string>;
}) {
    return (
        <>
            <Head title="Ally Studio" />
            <div className="grid max-w-5xl gap-6 p-4 text-base">
                <PageHeader
                    title="Ally Studio"
                    description="What each Studio package includes, what it costs RightAlly and the margin."
                />
                <p className="text-sm text-muted-foreground">
                    Set package prices under{' '}
                    <Link href="/hq/connect" className="underline">
                        Connect setup
                    </Link>
                    , credits for extra items on the rate card and costs in the
                    cost list, both under{' '}
                    <Link href="/hq/credits" className="underline">
                        RightAlly credits
                    </Link>
                    .
                </p>
                {packages.map((p) => (
                    <PackageCard key={p.key} pkg={p} features={features} />
                ))}
                <section className="grid gap-2">
                    <h2 className="font-medium">Beyond the allowance</h2>
                    <table className="w-full text-sm">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                <th className="py-2">Item</th>
                                <th>Credits</th>
                                <th>Price</th>
                                <th>Our cost</th>
                                <th>Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((i) => (
                                <tr key={i.item} className="border-t">
                                    <td className="py-2">{i.label}</td>
                                    <td>{i.credits}</td>
                                    <td>{money(i.price_cents)}</td>
                                    <td>{money(i.cost_cents)}</td>
                                    <td>
                                        {i.margin_pct === null
                                            ? '—'
                                            : `${i.margin_pct}%`}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            </div>
        </>
    );
}
