import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { postJson } from '@/lib/http';

type Param = {
    label: string;
    type: string;
    options?: Record<string, string>;
    required?: boolean;
};
type Catalogue = {
    triggers: Record<
        string,
        {
            key: string;
            label: string;
            subject: string;
            facts: Record<string, string>;
        }
    >;
    actions: Record<
        string,
        { label: string; subjects: string[]; params: Record<string, Param> }
    >;
    ops: Record<string, string>;
    approvers: Record<string, string>;
    fields: Record<string, Record<string, string>>;
};
type Value = string | number | boolean | null;
type Condition = { fact: string; op: string; value?: Value };
type Step = { type: string; params: Record<string, Value> };
type Draft = {
    name: string;
    trigger: string;
    conditions: Condition[];
    steps: Step[];
};
type Props = {
    automation:
        | (Draft & {
              ulid: string;
              description: string | null;
              on: boolean;
              version: number;
          })
        | null;
    catalogue: Catalogue;
};
type DryRun = {
    events: number;
    matched: number;
    examples: string[];
    steps: string[];
};

const select = 'rounded border bg-background px-2 py-1';

function StepEditor({
    step,
    catalogue,
    onChange,
    onRemove,
}: {
    step: Step;
    catalogue: Catalogue;
    onChange: (s: Step) => void;
    onRemove: () => void;
}) {
    const set = (key: string, value: Value) =>
        onChange({ ...step, params: { ...step.params, [key]: value } });
    const params: Record<string, Param> =
        step.type === 'wait'
            ? {
                  days: { label: 'Days', type: 'number' },
                  hours: { label: 'Hours', type: 'number' },
              }
            : step.type === 'approval'
              ? {
                    question: { label: 'What to approve', type: 'text' },
                    needed: {
                        label: 'How many approvals',
                        type: 'choice',
                        options: { '1': '1', '2': '2', '3': '3' },
                    },
                    permission: {
                        label: 'Who approves',
                        type: 'choice',
                        options: catalogue.approvers,
                    },
                }
              : (catalogue.actions[step.type]?.params ?? {});

    return (
        <div className="grid gap-2 rounded-md border p-3">
            <div className="flex items-center justify-between gap-2">
                <span className="font-medium">
                    {step.type === 'wait'
                        ? 'Wait'
                        : step.type === 'approval'
                          ? 'Ask for approval'
                          : (catalogue.actions[step.type]?.label ?? step.type)}
                </span>
                <Button size="sm" variant="ghost" onClick={onRemove}>
                    Remove
                </Button>
            </div>
            {Object.entries(params).map(([key, p]) => (
                <label key={key} className="grid gap-1">
                    <span className="text-muted-foreground">{p.label}</span>
                    {p.options ? (
                        <select
                            className={select}
                            value={String(step.params[key] ?? '')}
                            onChange={(e) => set(key, e.target.value)}
                        >
                            <option value="">Choose…</option>
                            {Object.entries(p.options).map(([v, l]) => (
                                <option key={v} value={v}>
                                    {l}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <Input
                            type={p.type === 'number' ? 'number' : 'text'}
                            value={String(step.params[key] ?? '')}
                            onChange={(e) =>
                                set(
                                    key,
                                    p.type === 'number'
                                        ? Number(e.target.value)
                                        : e.target.value,
                                )
                            }
                        />
                    )}
                </label>
            ))}
        </div>
    );
}

/** The automation builder (specs/core/06, W1): describe it, check it, dry-run it, save it. */
export default function EditAutomation({ automation, catalogue }: Props) {
    const [name, setName] = useState(automation?.name ?? '');
    const [trigger, setTrigger] = useState(automation?.trigger ?? '');
    const [conditions, setConditions] = useState<Condition[]>(
        automation?.conditions ?? [],
    );
    const [steps, setSteps] = useState<Step[]>(automation?.steps ?? []);
    const [ask, setAsk] = useState('');
    const [note, setNote] = useState<string | null>(null);
    const [dry, setDry] = useState<DryRun | null>(null);
    const [error, setError] = useState<string | null>(null);
    const def = catalogue.triggers[trigger];
    const facts: Record<string, string> = def
        ? { ...def.facts, ...catalogue.fields[def.subject] }
        : {};
    const actions = Object.entries(catalogue.actions).filter(
        ([, a]) =>
            !def || a.subjects.length === 0 || a.subjects.includes(def.subject),
    );

    const draft = () => {
        setNote('Building it…');
        void postJson<{ draft: Draft | null }>('/automations/draft', {
            text: ask,
        })
            .then((r) => {
                if (!r.draft) {
                    setNote(
                        "We couldn't build that from what RightAlly can do. Try different words, or build it below.",
                    );
                    return;
                }
                setName(r.draft.name);
                setTrigger(r.draft.trigger);
                setConditions(r.draft.conditions);
                setSteps(
                    r.draft.steps.map((s) => ({
                        type: s.type,
                        params: s.params ?? {},
                    })),
                );
                setNote(
                    'Here is a draft. Check every step, try a dry run, then save.',
                );
            })
            .catch((e: Error) => setNote(e.message));
    };
    const dryRun = () =>
        void postJson<DryRun>('/automations/dry-run', {
            trigger,
            conditions,
            steps,
        })
            .then((r) => {
                setDry(r);
                setError(null);
            })
            .catch((e: Error) => setError(e.message));
    const save = () => {
        const data = { name, trigger, conditions, steps };
        const opts = {
            onError: (e: Record<string, string>) =>
                setError(Object.values(e)[0] ?? null),
        };
        if (automation) {
            router.put(`/automations/${automation.ulid}`, data, opts);
        } else {
            router.post('/automations', data, opts);
        }
    };

    return (
        <>
            <Head title={automation ? automation.name : 'New automation'} />
            <div className="flex max-w-3xl flex-col gap-4 p-4 text-sm md:p-6">
                <PageHeader
                    title={automation ? automation.name : 'New automation'}
                    description={
                        automation
                            ? `Version ${automation.version}. Saving makes a new version; runs under way finish on theirs.`
                            : 'Describe it in plain English, or build it step by step.'
                    }
                    actions={
                        automation && (
                            <span className="flex items-center gap-2">
                                <Badge
                                    variant={
                                        automation.on ? 'default' : 'secondary'
                                    }
                                >
                                    {automation.on ? 'On' : 'Off'}
                                </Badge>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.delete(
                                            `/automations/${automation.ulid}`,
                                        )
                                    }
                                >
                                    Delete
                                </Button>
                            </span>
                        )
                    }
                />
                <section className="grid gap-2 rounded-lg border p-4">
                    <p className="font-medium">Describe it</p>
                    <Input
                        aria-label="Describe the automation"
                        placeholder="e.g. When a deal over $1M goes under contract, ask the managing broker to approve it"
                        value={ask}
                        onChange={(e) => setAsk(e.target.value)}
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        className="justify-self-start"
                        disabled={ask.trim() === ''}
                        onClick={draft}
                    >
                        Build it for me
                    </Button>
                    {note && <p className="text-muted-foreground">{note}</p>}
                </section>
                <section className="grid gap-3 rounded-lg border p-4">
                    <Input
                        aria-label="Name"
                        placeholder="Name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                    />
                    <label className="grid gap-1">
                        <span className="font-medium">When</span>
                        <select
                            className={select}
                            value={trigger}
                            onChange={(e) => {
                                setTrigger(e.target.value);
                                setConditions([]);
                            }}
                        >
                            <option value="">Choose what starts it…</option>
                            {Object.values(catalogue.triggers).map((t) => (
                                <option key={t.key} value={t.key}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    {def && (
                        <div className="grid gap-2">
                            <span className="font-medium">Only if</span>
                            {conditions.map((c, i) => (
                                <div
                                    key={i}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <select
                                        aria-label="Fact"
                                        className={select}
                                        value={c.fact}
                                        onChange={(e) => {
                                            const next = [...conditions];
                                            next[i] = {
                                                ...c,
                                                fact: e.target.value,
                                            };
                                            setConditions(next);
                                        }}
                                    >
                                        {Object.entries(facts).map(([k, l]) => (
                                            <option key={k} value={k}>
                                                {l}
                                            </option>
                                        ))}
                                    </select>
                                    <select
                                        aria-label="How"
                                        className={select}
                                        value={c.op}
                                        onChange={(e) => {
                                            const next = [...conditions];
                                            next[i] = {
                                                ...c,
                                                op: e.target.value,
                                            };
                                            setConditions(next);
                                        }}
                                    >
                                        {Object.entries(catalogue.ops).map(
                                            ([k, l]) => (
                                                <option key={k} value={k}>
                                                    {l}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                    {!['filled', 'empty'].includes(c.op) && (
                                        <Input
                                            aria-label="Value"
                                            className="w-40"
                                            value={String(c.value ?? '')}
                                            onChange={(e) => {
                                                const next = [...conditions];
                                                next[i] = {
                                                    ...c,
                                                    value: e.target.value,
                                                };
                                                setConditions(next);
                                            }}
                                        />
                                    )}
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            setConditions(
                                                conditions.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            )
                                        }
                                    >
                                        Remove
                                    </Button>
                                </div>
                            ))}
                            <Button
                                size="sm"
                                variant="outline"
                                className="justify-self-start"
                                onClick={() =>
                                    setConditions([
                                        ...conditions,
                                        {
                                            fact: Object.keys(facts)[0] ?? '',
                                            op: 'is',
                                            value: '',
                                        },
                                    ])
                                }
                            >
                                Add a condition
                            </Button>
                        </div>
                    )}
                    <div className="grid gap-2">
                        <span className="font-medium">Then</span>
                        {steps.map((s, i) => (
                            <StepEditor
                                key={i}
                                step={s}
                                catalogue={catalogue}
                                onChange={(next) =>
                                    setSteps(
                                        steps.map((x, j) =>
                                            j === i ? next : x,
                                        ),
                                    )
                                }
                                onRemove={() =>
                                    setSteps(steps.filter((_, j) => j !== i))
                                }
                            />
                        ))}
                        <select
                            aria-label="Add a step"
                            className={select}
                            value=""
                            onChange={(e) =>
                                e.target.value &&
                                setSteps([
                                    ...steps,
                                    {
                                        type: e.target.value,
                                        params:
                                            e.target.value === 'approval'
                                                ? {
                                                      needed: '1',
                                                      permission:
                                                          'core.workflows.manage',
                                                  }
                                                : {},
                                    },
                                ])
                            }
                        >
                            <option value="">Add a step…</option>
                            {actions.map(([k, a]) => (
                                <option key={k} value={k}>
                                    {a.label}
                                </option>
                            ))}
                            <option value="wait">Wait</option>
                            <option value="approval">Ask for approval</option>
                        </select>
                    </div>
                </section>
                {error && (
                    <p role="alert" className="text-destructive">
                        {error}
                    </p>
                )}
                {dry && (
                    <section className="grid gap-1 rounded-lg border p-4">
                        <p className="font-medium">Dry run: the last 30 days</p>
                        <p>
                            It would have run {dry.matched} time
                            {dry.matched === 1 ? '' : 's'} (out of {dry.events}{' '}
                            times this happened).
                        </p>
                        {dry.examples.length > 0 && (
                            <p className="text-muted-foreground">
                                For example: {dry.examples.join('; ')}
                            </p>
                        )}
                        <ol className="list-decimal pl-5">
                            {dry.steps.map((s, i) => (
                                <li key={i}>{s}</li>
                            ))}
                        </ol>
                        <p className="text-muted-foreground">
                            Nothing was changed.
                        </p>
                    </section>
                )}
                <span className="flex gap-2">
                    <Button
                        variant="outline"
                        disabled={!trigger || steps.length === 0}
                        onClick={dryRun}
                    >
                        Dry run
                    </Button>
                    <Button
                        disabled={
                            !trigger || steps.length === 0 || name.trim() === ''
                        }
                        onClick={save}
                    >
                        Save
                    </Button>
                </span>
            </div>
        </>
    );
}

EditAutomation.layout = {
    breadcrumbs: [{ title: 'Automations', href: '/automations' }],
};
