import { router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type CeProgress = {
    state: string;
    renewal: string;
    cycle_start: string;
    required: string;
    done: string;
    complete: boolean;
    topics: { topic: string; required: string; done: string }[];
    courses: {
        ulid: string;
        title: string;
        provider: string | null;
        topic: string | null;
        hours: string;
        completed_on: string;
    }[];
};

/** Continuing education for each licence: hours and required topics before the next renewal. */
export function CePanel({
    agent,
    ce,
    canManage,
    canLog,
}: {
    agent: string;
    ce: CeProgress[];
    canManage: boolean;
    canLog: boolean;
}) {
    const form = useForm<{
        state: string;
        title: string;
        provider: string;
        topic: string;
        hours: string;
        completed_on: string;
        file: File | null;
    }>({
        state: ce[0]?.state ?? '',
        title: '',
        provider: '',
        topic: '',
        hours: '',
        completed_on: new Date().toISOString().slice(0, 10),
        file: null,
    });

    return (
        <section className="grid gap-2 rounded-lg border p-4 text-sm">
            <h2 className="font-semibold">Continuing education</h2>
            {ce.length === 0 && (
                <p className="text-muted-foreground">
                    No CE rule is set for this agent's licence states yet.
                </p>
            )}
            {ce.map((p) => (
                <div key={p.state} className="grid gap-1">
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">
                            {p.state}: {p.done} of {p.required} hours
                        </span>
                        <span className="text-muted-foreground">
                            for the renewal on{' '}
                            {new Date(p.renewal).toLocaleDateString()}
                        </span>
                        <Badge variant={p.complete ? 'secondary' : 'outline'}>
                            {p.complete ? 'Complete' : 'In progress'}
                        </Badge>
                    </span>
                    {p.topics.map((t) => (
                        <span key={t.topic} className="text-muted-foreground">
                            {t.topic}: {t.done} of {t.required} hours
                        </span>
                    ))}
                    <ul className="ml-4 list-disc">
                        {p.courses.map((c) => (
                            <li key={c.ulid}>
                                {c.title}
                                {c.provider ? ` (${c.provider})` : ''}
                                {c.topic ? ` · ${c.topic}` : ''} · {c.hours} h ·{' '}
                                {new Date(c.completed_on).toLocaleDateString()}
                                {canManage && (
                                    <button
                                        type="button"
                                        className="ml-2 text-xs underline"
                                        onClick={() =>
                                            router.post(
                                                `/roster/ce/courses/${c.ulid}/remove`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Remove
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
            {canLog && ce.length > 0 && (
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/roster/${agent}/ce`, {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () =>
                                form.reset(
                                    'title',
                                    'provider',
                                    'topic',
                                    'hours',
                                    'file',
                                ),
                        });
                    }}
                >
                    <select
                        aria-label="Licence state"
                        className="rounded border bg-background px-2 py-1.5"
                        value={form.data.state}
                        onChange={(e) => form.setData('state', e.target.value)}
                    >
                        {ce.map((p) => (
                            <option key={p.state} value={p.state}>
                                {p.state}
                            </option>
                        ))}
                    </select>
                    <Input
                        aria-label="Course title"
                        placeholder="Course title"
                        className="w-48"
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                    <Input
                        aria-label="Provider"
                        placeholder="Provider"
                        className="w-36"
                        value={form.data.provider}
                        onChange={(e) =>
                            form.setData('provider', e.target.value)
                        }
                    />
                    <Input
                        aria-label="Topic"
                        placeholder="Topic (e.g. Ethics)"
                        className="w-36"
                        value={form.data.topic}
                        onChange={(e) => form.setData('topic', e.target.value)}
                    />
                    <Input
                        aria-label="Hours"
                        type="number"
                        step="0.5"
                        min={0.5}
                        placeholder="Hours"
                        className="w-24"
                        value={form.data.hours}
                        onChange={(e) => form.setData('hours', e.target.value)}
                    />
                    <Input
                        aria-label="Completed on"
                        type="date"
                        className="w-40"
                        value={form.data.completed_on}
                        onChange={(e) =>
                            form.setData('completed_on', e.target.value)
                        }
                    />
                    <input
                        aria-label="Certificate"
                        type="file"
                        accept="application/pdf,image/*"
                        onChange={(e) =>
                            form.setData('file', e.target.files?.[0] ?? null)
                        }
                    />
                    <Button type="submit" size="sm" variant="outline">
                        Log course
                    </Button>
                    {Object.values(form.errors)[0] && (
                        <p role="alert" className="w-full text-destructive">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                </form>
            )}
        </section>
    );
}
