import { Head, router, useForm } from '@inertiajs/react';
import { PlusIcon, TrashIcon } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Step = {
    day: number;
    type: 'call' | 'text' | 'email' | 'task';
    title: string;
    message: string | null;
};
type Plan = {
    ulid: string;
    name: string;
    description: string | null;
    trigger: 'manual' | 'source' | 'stage';
    trigger_value: string | null;
    active: boolean;
    shared: boolean;
    can_edit: boolean;
    enrolled: number;
    steps: Step[];
};
type Props = {
    plans: Plan[];
    sources: string[];
    stages: { ulid: string; name: string }[];
    isManager: boolean;
};

const label = (v: string) => v.replace(/_/g, ' ');
const blankStep = (day: number): Step => ({
    day,
    type: 'call',
    title: '',
    message: null,
});

function Editor({
    plan,
    onClose,
    sources,
    stages,
    isManager,
}: { plan: Plan | null; onClose: () => void } & Omit<Props, 'plans'>) {
    const form = useForm({
        name: plan?.name ?? '',
        description: plan?.description ?? '',
        trigger: plan?.trigger ?? 'manual',
        trigger_value: plan?.trigger_value ?? '',
        shared: plan?.shared ?? false,
        steps: plan?.steps ?? [blankStep(0)],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const setStep = (i: number, patch: Partial<Step>) =>
        form.setData(
            'steps',
            form.data.steps.map((s, j) => (j === i ? { ...s, ...patch } : s)),
        );

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {plan?.can_edit ? 'Edit plan' : 'New plan'}
                    </DialogTitle>
                    <DialogDescription>
                        Steps arrive as tasks on their day. Use {'{first_name}'}{' '}
                        and {'{agent_first_name}'} in messages.
                    </DialogDescription>
                </DialogHeader>
                <form
                    id="plan-form"
                    className="grid gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const done = { onSuccess: onClose };
                        if (plan?.can_edit) {
                            form.put(`/follow-up-plans/${plan.ulid}`, done);
                        } else {
                            form.post('/follow-up-plans', done);
                        }
                    }}
                >
                    <Input
                        aria-label="Plan name"
                        placeholder="Plan name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    {errors.name && (
                        <p className="text-sm text-destructive">
                            {errors.name}
                        </p>
                    )}
                    <Input
                        aria-label="Description"
                        placeholder="What is it for?"
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                    />
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span>Start</span>
                        <select
                            aria-label="When to start"
                            className="rounded border bg-background px-2 py-1"
                            value={form.data.trigger}
                            onChange={(e) =>
                                form.setData(
                                    'trigger',
                                    e.target.value as Plan['trigger'],
                                )
                            }
                        >
                            <option value="manual">when I choose</option>
                            <option value="source">
                                automatically for new contacts from
                            </option>
                            <option value="stage">
                                automatically when moved to
                            </option>
                        </select>
                        {form.data.trigger === 'source' && (
                            <select
                                aria-label="Source"
                                className="rounded border bg-background px-2 py-1"
                                value={form.data.trigger_value}
                                onChange={(e) =>
                                    form.setData(
                                        'trigger_value',
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="">choose…</option>
                                {sources.map((s) => (
                                    <option key={s} value={s}>
                                        {label(s)}
                                    </option>
                                ))}
                            </select>
                        )}
                        {form.data.trigger === 'stage' && (
                            <select
                                aria-label="Stage"
                                className="rounded border bg-background px-2 py-1"
                                value={form.data.trigger_value}
                                onChange={(e) =>
                                    form.setData(
                                        'trigger_value',
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="">choose…</option>
                                {stages.map((s) => (
                                    <option key={s.ulid} value={s.ulid}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>
                    {errors.trigger && (
                        <p className="text-sm text-destructive">
                            {errors.trigger}
                        </p>
                    )}
                    {isManager && (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={form.data.shared}
                                onChange={(e) =>
                                    form.setData('shared', e.target.checked)
                                }
                            />
                            Share with the whole brokerage
                        </label>
                    )}
                    <ol className="grid gap-3">
                        {form.data.steps.map((step, i) => (
                            <li
                                key={i}
                                className="grid gap-2 rounded-md border p-2"
                            >
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                    <span>Day</span>
                                    <Input
                                        aria-label={`Day of step ${i + 1}`}
                                        type="number"
                                        min={0}
                                        max={365}
                                        className="w-20"
                                        value={step.day}
                                        onChange={(e) =>
                                            setStep(i, {
                                                day: Number(e.target.value),
                                            })
                                        }
                                    />
                                    <select
                                        aria-label={`Type of step ${i + 1}`}
                                        className="rounded border bg-background px-2 py-1"
                                        value={step.type}
                                        onChange={(e) =>
                                            setStep(i, {
                                                type: e.target
                                                    .value as Step['type'],
                                            })
                                        }
                                    >
                                        <option value="call">Call</option>
                                        <option value="text">Text</option>
                                        <option value="email">Email</option>
                                        <option value="task">Task</option>
                                    </select>
                                    <Input
                                        aria-label={`What to do in step ${i + 1}`}
                                        className="min-w-48 flex-1"
                                        value={step.title}
                                        onChange={(e) =>
                                            setStep(i, {
                                                title: e.target.value,
                                            })
                                        }
                                    />
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        aria-label={`Remove step ${i + 1}`}
                                        onClick={() =>
                                            form.setData(
                                                'steps',
                                                form.data.steps.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            )
                                        }
                                    >
                                        <TrashIcon className="size-4" />
                                    </Button>
                                </div>
                                {(step.type === 'text' ||
                                    step.type === 'email') && (
                                    <Textarea
                                        aria-label={`Message for step ${i + 1}`}
                                        placeholder="Message"
                                        value={step.message ?? ''}
                                        onChange={(e) =>
                                            setStep(i, {
                                                message: e.target.value,
                                            })
                                        }
                                    />
                                )}
                                {(errors[`steps.${i}.day`] ||
                                    errors[`steps.${i}.title`] ||
                                    errors[`steps.${i}.message`]) && (
                                    <p className="text-sm text-destructive">
                                        {errors[`steps.${i}.day`] ??
                                            errors[`steps.${i}.title`] ??
                                            errors[`steps.${i}.message`]}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ol>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="justify-self-start"
                        onClick={() =>
                            form.setData('steps', [
                                ...form.data.steps,
                                blankStep(
                                    (form.data.steps.at(-1)?.day ?? 0) + 1,
                                ),
                            ])
                        }
                    >
                        <PlusIcon aria-hidden="true" /> Add step
                    </Button>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        form="plan-form"
                        disabled={form.processing}
                    >
                        Save plan
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function Plans({ plans, sources, stages, isManager }: Props) {
    const [editing, setEditing] = useState<Plan | null | undefined>(undefined);

    return (
        <>
            <Head title="Follow-up plans" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Follow-up plans"
                    description="Proven follow-up that arrives as tasks on the right day, and stops when the person responds."
                    actions={
                        <Button onClick={() => setEditing(null)}>
                            <PlusIcon aria-hidden="true" /> New plan
                        </Button>
                    }
                />
                <div className="grid gap-4 md:grid-cols-2">
                    {plans.map((p) => (
                        <article
                            key={p.ulid}
                            className="grid content-start gap-2 rounded-lg border p-4"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <h2 className="font-semibold">{p.name}</h2>
                                    {p.description && (
                                        <p className="text-sm text-muted-foreground">
                                            {p.description}
                                        </p>
                                    )}
                                </div>
                                <div className="flex gap-1">
                                    {p.shared && (
                                        <Badge variant="secondary">
                                            Brokerage
                                        </Badge>
                                    )}
                                    {!p.active && (
                                        <Badge variant="outline">Paused</Badge>
                                    )}
                                </div>
                            </div>
                            <ol className="grid gap-0.5 text-sm">
                                {p.steps.map((s, i) => (
                                    <li key={i}>
                                        <span className="text-muted-foreground">
                                            Day {s.day} ·{' '}
                                        </span>
                                        <span className="capitalize">
                                            {s.type}
                                        </span>{' '}
                                        · {s.title}
                                    </li>
                                ))}
                            </ol>
                            <p className="text-xs text-muted-foreground">
                                {p.trigger === 'manual'
                                    ? 'Started by hand'
                                    : p.trigger === 'source'
                                      ? `Automatic for ${label(p.trigger_value ?? '')} contacts`
                                      : 'Automatic on a stage'}{' '}
                                · {p.enrolled} active
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {p.can_edit && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setEditing(p)}
                                    >
                                        Edit
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setEditing({
                                            ...p,
                                            can_edit: false,
                                            shared: false,
                                            name: `${p.name} (my copy)`,
                                        })
                                    }
                                >
                                    Make my copy
                                </Button>
                                {p.can_edit && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.post(
                                                `/follow-up-plans/${p.ulid}/toggle`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {p.active ? 'Pause' : 'Resume'}
                                    </Button>
                                )}
                            </div>
                        </article>
                    ))}
                </div>
            </div>
            {editing !== undefined && (
                <Editor
                    plan={editing}
                    onClose={() => setEditing(undefined)}
                    sources={sources}
                    stages={stages}
                    isManager={isManager}
                />
            )}
        </>
    );
}

Plans.layout = {
    breadcrumbs: [
        { title: 'Contacts', href: '/contacts' },
        { title: 'Follow-up plans', href: '/follow-up-plans' },
    ],
};
