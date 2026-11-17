import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { RulesForm, type Rules } from '@/components/compensation/rules-form';
import { confirmCredits } from '@/components/credit-consent';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Version = {
    ulid: string;
    version: number;
    status: string;
    effective_from: string | null;
    rules: Rules;
    note: string | null;
};
type Plan = {
    ulid: string;
    name: string;
    type: string;
    default: boolean;
    status: string;
    description: string | null;
    agents: number;
    versions: Version[];
};
type Reading = {
    plan: string;
    name: string;
    summary: string;
    evidence: { rule: string; quote: string }[];
    unclear: string[];
};
type Props = {
    reading?: Reading | null;
    plans: Plan[];
    agents: { ulid: string; name: string; plan: string | null }[];
    fees: { code: string; name: string }[];
    canManage: boolean;
};

function Errors() {
    const errors = usePage().props.errors as Record<string, string>;
    const list = Object.entries(errors ?? {});
    return list.length === 0 ? null : (
        <ul
            role="alert"
            className="rounded-md border border-destructive/40 p-2 text-sm text-destructive"
        >
            {list.map(([k, v]) => (
                <li key={k}>{v}</li>
            ))}
        </ul>
    );
}

function PlanCard({
    plan,
    fees,
    canManage,
}: {
    plan: Plan;
    fees: Props['fees'];
    canManage: boolean;
}) {
    const draft = plan.versions.find((v) => v.status === 'draft');
    const published = plan.versions.find((v) => v.status === 'published');
    const [editing, setEditing] = useState(false);
    const [rules, setRules] = useState<Rules>(
        draft?.rules ?? published?.rules ?? {},
    );
    const [from, setFrom] = useState(new Date().toISOString().slice(0, 10));

    return (
        <li className="grid gap-2 rounded-lg border p-4 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="flex items-center gap-2 font-semibold">
                    {plan.name}
                    {plan.default && <Badge>Default</Badge>}
                    <Badge variant="outline" className="capitalize">
                        {plan.type}
                    </Badge>
                    {plan.status !== 'active' && (
                        <Badge variant="secondary">{plan.status}</Badge>
                    )}
                </span>
                <span className="text-muted-foreground">
                    {plan.agents} agent(s)
                </span>
            </div>
            <ol className="grid gap-0.5 text-muted-foreground">
                {plan.versions.map((v) => (
                    <li key={v.ulid}>
                        Version {v.version} · {v.status}
                        {v.effective_from
                            ? ` from ${new Date(v.effective_from).toLocaleDateString()}`
                            : ''}
                        {v.note ? ` · ${v.note}` : ''}
                    </li>
                ))}
            </ol>
            {canManage && (
                <>
                    <span className="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setEditing(!editing)}
                        >
                            {editing
                                ? 'Close'
                                : draft
                                  ? 'Edit draft'
                                  : 'New version'}
                        </Button>
                        {!plan.default &&
                            plan.type === 'commission' &&
                            published && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        router.post(
                                            `/compensation/plans/${plan.ulid}/default`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Make default
                                </Button>
                            )}
                        {!plan.default && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.post(
                                        `/compensation/plans/${plan.ulid}/archive`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Archive
                            </Button>
                        )}
                    </span>
                    {editing && (
                        <div className="grid gap-3 rounded-md bg-muted/30 p-3">
                            <RulesForm
                                value={rules}
                                onChange={setRules}
                                fees={fees}
                            />
                            <span className="flex flex-wrap items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.put(
                                            `/compensation/plans/${plan.ulid}/draft`,
                                            {
                                                rules: JSON.parse(
                                                    JSON.stringify(rules),
                                                ),
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Save draft
                                </Button>
                                {draft && (
                                    <>
                                        <Input
                                            aria-label="Publish from"
                                            type="date"
                                            className="w-40"
                                            value={from}
                                            onChange={(e) =>
                                                setFrom(e.target.value)
                                            }
                                        />
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/compensation/versions/${draft.ulid}/publish`,
                                                    { effective_from: from },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Publish
                                        </Button>
                                    </>
                                )}
                            </span>
                            <p className="text-xs text-muted-foreground">
                                Published versions never change. Publishing
                                recalculates pending deals automatically.
                            </p>
                        </div>
                    )}
                </>
            )}
        </li>
    );
}

export default function Plans({
    plans,
    agents,
    fees,
    canManage,
    reading,
}: Props) {
    const [creating, setCreating] = useState(false);
    const [name, setName] = useState('');
    const [type, setType] = useState('commission');
    const [rules, setRules] = useState<Rules>({
        split: { mode: 'percentage', agent_percent: '80' },
    });
    const [assign, setAssign] = useState({
        agent: '',
        plan: '',
        from: new Date().toISOString().slice(0, 10),
    });

    return (
        <>
            <Head title="Compensation plans" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Compensation plans"
                    description="Splits, caps and fees. Every published version is kept forever."
                    actions={
                        canManage && (
                            <Button onClick={() => setCreating(!creating)}>
                                New plan
                            </Button>
                        )
                    }
                />
                <Errors />
                {canManage && <ReadPlan reading={reading ?? null} />}
                {creating && (
                    <section className="grid gap-3 rounded-lg border p-4">
                        <span className="flex flex-wrap gap-2">
                            <Input
                                aria-label="Plan name"
                                placeholder="80/20 with $16k cap"
                                className="max-w-xs"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                            />
                            <select
                                aria-label="Plan type"
                                className="rounded border bg-background px-2 py-1.5"
                                value={type}
                                onChange={(e) => setType(e.target.value)}
                            >
                                <option value="commission">
                                    Commission plan
                                </option>
                                <option value="onboarding">
                                    Onboarding plan
                                </option>
                            </select>
                        </span>
                        <RulesForm
                            value={rules}
                            onChange={setRules}
                            fees={fees}
                        />
                        <Button
                            className="justify-self-start"
                            disabled={name.trim() === ''}
                            onClick={() =>
                                router.post(
                                    '/compensation/plans',
                                    {
                                        name,
                                        type,
                                        rules: JSON.parse(
                                            JSON.stringify(rules),
                                        ),
                                    },
                                    { onSuccess: () => setCreating(false) },
                                )
                            }
                        >
                            Save as draft
                        </Button>
                    </section>
                )}
                <ul className="grid gap-3 md:grid-cols-2">
                    {plans.map((p) => (
                        <PlanCard
                            key={p.ulid}
                            plan={p}
                            fees={fees}
                            canManage={canManage}
                        />
                    ))}
                </ul>
                <section className="grid gap-2">
                    <h2 className="font-semibold">Who is on which plan</h2>
                    <ul className="divide-y rounded-lg border text-sm">
                        {agents.map((a) => (
                            <li
                                key={a.ulid}
                                className="flex justify-between p-2"
                            >
                                <span>{a.name}</span>
                                <span className="text-muted-foreground">
                                    {a.plan ?? 'Default plan'}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {canManage && (
                        <span className="flex flex-wrap gap-2 text-sm">
                            <select
                                aria-label="Agent"
                                className="rounded border bg-background px-2 py-1.5"
                                value={assign.agent}
                                onChange={(e) =>
                                    setAssign({
                                        ...assign,
                                        agent: e.target.value,
                                    })
                                }
                            >
                                <option value="">Agent…</option>
                                {agents.map((a) => (
                                    <option key={a.ulid} value={a.ulid}>
                                        {a.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Plan"
                                className="rounded border bg-background px-2 py-1.5"
                                value={assign.plan}
                                onChange={(e) =>
                                    setAssign({
                                        ...assign,
                                        plan: e.target.value,
                                    })
                                }
                            >
                                <option value="">Plan…</option>
                                {plans
                                    .filter((p) => p.status === 'active')
                                    .map((p) => (
                                        <option key={p.ulid} value={p.ulid}>
                                            {p.name}
                                        </option>
                                    ))}
                            </select>
                            <Input
                                aria-label="From"
                                type="date"
                                className="w-40"
                                value={assign.from}
                                onChange={(e) =>
                                    setAssign({
                                        ...assign,
                                        from: e.target.value,
                                    })
                                }
                            />
                            <Button
                                variant="outline"
                                disabled={!assign.agent || !assign.plan}
                                onClick={() =>
                                    router.post(
                                        '/compensation/assignments',
                                        assign,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Move to plan
                            </Button>
                        </span>
                    )}
                </section>
            </div>
        </>
    );
}

Plans.layout = {
    breadcrumbs: [{ title: 'Compensation plans', href: '/compensation/plans' }],
};

/** CP1: set a plan up from the brokerage's own plan document; it becomes a draft to check, never published by itself. */
function ReadPlan({ reading }: { reading: Reading | null }) {
    const form = useForm<{ file: File | null; text: string }>({
        file: null,
        text: '',
    });
    const [open, setOpen] = useState(false);

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-semibold">
                    Set a plan up from your plan document
                </h2>
                <Button
                    size="sm"
                    variant="outline"
                    className="ml-auto"
                    onClick={() => setOpen(!open)}
                >
                    {open ? 'Close' : 'Upload the plan (AI)'}
                </Button>
            </div>
            {open && (
                <form
                    className="grid gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        void confirmCredits('ai.plan_reader').then(
                            (ok) =>
                                ok &&
                                form.post('/compensation/plans/read', {
                                    forceFormData: true,
                                    preserveScroll: true,
                                }),
                        );
                    }}
                >
                    <p className="text-muted-foreground">
                        Upload the PDF your agents sign, or paste its words. We
                        make a draft plan and show the sentence behind every
                        number. Nothing changes for agents until you publish.
                    </p>
                    <input
                        type="file"
                        accept="application/pdf"
                        onChange={(e) =>
                            form.setData('file', e.target.files?.[0] ?? null)
                        }
                    />
                    <textarea
                        className="min-h-24 rounded-md border bg-background p-2"
                        placeholder="Or paste the plan's words here"
                        value={form.data.text}
                        onChange={(e) => form.setData('text', e.target.value)}
                    />
                    {form.errors.file && (
                        <p className="text-destructive">{form.errors.file}</p>
                    )}
                    <div>
                        <Button
                            disabled={
                                form.processing ||
                                (form.data.file === null &&
                                    form.data.text.trim() === '')
                            }
                        >
                            Read it
                        </Button>
                    </div>
                </form>
            )}
            {reading && (
                <div className="grid gap-2 rounded-md bg-muted/40 p-3">
                    <p className="font-medium">Draft made: {reading.name}</p>
                    {reading.summary && <p>{reading.summary}</p>}
                    {reading.evidence.length > 0 && (
                        <ul className="grid gap-1">
                            {reading.evidence.map((e, i) => (
                                <li key={i}>
                                    <strong>{e.rule}:</strong>{' '}
                                    <q className="text-muted-foreground">
                                        {e.quote}
                                    </q>
                                </li>
                            ))}
                        </ul>
                    )}
                    {reading.unclear.length > 0 && (
                        <div className="rounded-md border border-amber-300 p-2">
                            <p className="font-medium">
                                Check these before publishing
                            </p>
                            <ul className="list-disc pl-5">
                                {reading.unclear.map((u) => (
                                    <li key={u}>{u}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
