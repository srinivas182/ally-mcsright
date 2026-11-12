import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';
import { postJson } from '@/lib/http';

type Props = {
    agents: { ulid: string; name: string }[];
    dealClasses: string[];
};
type Result = {
    steps: string[];
    agents: {
        user_id: number;
        name: string;
        plan: string;
        company_cents: number;
        agent_net_cents: number;
        team_split_cents: number;
        capped: boolean;
    }[];
};

/** "What would this deal pay?" — the real calculation with each agent's real cap state; nothing is saved. */
export default function Simulator({ agents, dealClasses }: Props) {
    const [form, setForm] = useState({
        gci: '',
        sale_price: '',
        closing_date: new Date().toISOString().slice(0, 10),
        side: 'listing',
        deal_class: 'sale',
        referral_percent: '',
    });
    const [people, setPeople] = useState([
        { ulid: agents[0]?.ulid ?? '', share: '100' },
    ]);
    const [result, setResult] = useState<Result | null>(null);
    const [error, setError] = useState<string | null>(null);

    const run = async () => {
        setError(null);
        try {
            setResult(
                await postJson<Result>('/compensation/simulate', {
                    ...form,
                    referral_percent: form.referral_percent || null,
                    agents: people,
                }),
            );
        } catch (e) {
            setResult(null);
            setError((e as Error).message);
        }
    };

    return (
        <>
            <Head title="Simulator" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Simulator"
                    description="What would this deal pay? Uses each agent's real plan and cap progress. Nothing is saved."
                />
                <div className="grid gap-2 md:grid-cols-3">
                    <Input
                        aria-label="GCI ($)"
                        placeholder="GCI $"
                        type="number"
                        value={form.gci}
                        onChange={(e) =>
                            setForm({ ...form, gci: e.target.value })
                        }
                    />
                    <Input
                        aria-label="Sale price ($)"
                        placeholder="Sale price $"
                        type="number"
                        value={form.sale_price}
                        onChange={(e) =>
                            setForm({ ...form, sale_price: e.target.value })
                        }
                    />
                    <Input
                        aria-label="Closing date"
                        type="date"
                        value={form.closing_date}
                        onChange={(e) =>
                            setForm({ ...form, closing_date: e.target.value })
                        }
                    />
                    <select
                        aria-label="Side"
                        className="rounded border bg-background px-2 py-1.5"
                        value={form.side}
                        onChange={(e) =>
                            setForm({ ...form, side: e.target.value })
                        }
                    >
                        <option value="listing">Listing side</option>
                        <option value="buying">Buying side</option>
                    </select>
                    <select
                        aria-label="Deal class"
                        className="rounded border bg-background px-2 py-1.5 capitalize"
                        value={form.deal_class}
                        onChange={(e) =>
                            setForm({ ...form, deal_class: e.target.value })
                        }
                    >
                        {dealClasses.map((c) => (
                            <option key={c} value={c}>
                                {c.replace('_', ' ')}
                            </option>
                        ))}
                    </select>
                    <Input
                        aria-label="Outside referral %"
                        placeholder="Outside referral % (optional)"
                        type="number"
                        value={form.referral_percent}
                        onChange={(e) =>
                            setForm({
                                ...form,
                                referral_percent: e.target.value,
                            })
                        }
                    />
                </div>
                <div className="grid gap-2">
                    {people.map((p, i) => (
                        <div key={i} className="flex flex-wrap gap-2">
                            <select
                                aria-label={`Agent ${i + 1}`}
                                className="rounded border bg-background px-2 py-1.5"
                                value={p.ulid}
                                onChange={(e) =>
                                    setPeople(
                                        people.map((x, j) =>
                                            j === i
                                                ? { ...x, ulid: e.target.value }
                                                : x,
                                        ),
                                    )
                                }
                            >
                                {agents.map((a) => (
                                    <option key={a.ulid} value={a.ulid}>
                                        {a.name}
                                    </option>
                                ))}
                            </select>
                            <Input
                                aria-label={`Share for agent ${i + 1} (%)`}
                                type="number"
                                className="w-28"
                                value={p.share}
                                onChange={(e) =>
                                    setPeople(
                                        people.map((x, j) =>
                                            j === i
                                                ? {
                                                      ...x,
                                                      share: e.target.value,
                                                  }
                                                : x,
                                        ),
                                    )
                                }
                            />
                        </div>
                    ))}
                    <span className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setPeople([
                                    ...people,
                                    {
                                        ulid: agents[0]?.ulid ?? '',
                                        share: '50',
                                    },
                                ])
                            }
                        >
                            Add co-agent
                        </Button>
                        <Button
                            onClick={() => void run()}
                            disabled={form.gci === ''}
                        >
                            Calculate
                        </Button>
                    </span>
                </div>
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                {result && (
                    <section className="grid gap-3 md:grid-cols-2">
                        <ul className="grid content-start gap-2">
                            {result.agents.map((a) => (
                                <li
                                    key={a.user_id}
                                    className="grid gap-1 rounded-lg border p-3 text-sm"
                                >
                                    <span className="font-medium">
                                        {a.name} · {a.plan}
                                        {a.capped ? ' · capped' : ''}
                                    </span>
                                    <span>
                                        Agent receives{' '}
                                        <strong>
                                            {usd(a.agent_net_cents)}
                                        </strong>
                                    </span>
                                    <span className="text-muted-foreground">
                                        Company dollar {usd(a.company_cents)}
                                        {a.team_split_cents
                                            ? ` · team split ${usd(a.team_split_cents)}`
                                            : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <ol className="grid content-start gap-1 rounded-lg bg-muted/40 p-3 text-sm">
                            {result.steps.map((s, i) => (
                                <li key={i}>{s}</li>
                            ))}
                        </ol>
                    </section>
                )}
            </div>
        </>
    );
}

Simulator.layout = {
    breadcrumbs: [{ title: 'Simulator', href: '/compensation/simulator' }],
};
