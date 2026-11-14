import { router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type KeyDate = {
    id: number;
    label: string;
    due_on: string;
    done: boolean;
    source: string;
    overdue: boolean;
    soon: boolean;
};

const nice = (d: string) =>
    new Date(`${d}T12:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/** A deal's key dates: tick off when done; reminders go to the agent before each one. */
export function KeyDates({ deal, dates }: { deal: string; dates: KeyDate[] }) {
    const form = useForm({ label: '', due_on: '' });
    const opts = { preserveScroll: true };

    return (
        <section id="key-dates" className="grid gap-2">
            <h2 className="font-semibold">Key dates</h2>
            {dates.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No key dates yet. Add them here, or from a forms packet's
                    plain-English summary.
                </p>
            ) : (
                <ul className="divide-y rounded-lg border text-sm">
                    {dates.map((d) => (
                        <li
                            key={d.id}
                            className="flex flex-wrap items-center gap-2 p-2"
                        >
                            <input
                                type="checkbox"
                                className="size-4"
                                aria-label={`${d.label} done`}
                                checked={d.done}
                                onChange={() =>
                                    router.post(
                                        `/deals/${deal}/dates/${d.id}/toggle`,
                                        {},
                                        opts,
                                    )
                                }
                            />
                            <span
                                className={
                                    d.done
                                        ? 'text-muted-foreground line-through'
                                        : ''
                                }
                            >
                                {d.label}
                            </span>
                            <span className="text-muted-foreground">
                                {nice(d.due_on)}
                            </span>
                            {d.overdue && (
                                <Badge variant="destructive">Missed</Badge>
                            )}
                            {d.soon && <Badge variant="outline">Soon</Badge>}
                            {d.source === 'forms' && (
                                <Badge variant="secondary">From forms</Badge>
                            )}
                            <button
                                type="button"
                                className="ml-auto text-xs underline"
                                onClick={() =>
                                    router.delete(
                                        `/deals/${deal}/dates/${d.id}`,
                                        opts,
                                    )
                                }
                            >
                                Remove
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <form
                className="flex flex-wrap gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/deals/${deal}/dates`, {
                        ...opts,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <Input
                    aria-label="Date name"
                    placeholder="e.g. Inspection period ends"
                    className="w-64"
                    value={form.data.label}
                    onChange={(e) => form.setData('label', e.target.value)}
                />
                <Input
                    aria-label="Due on"
                    type="date"
                    className="w-44"
                    value={form.data.due_on}
                    onChange={(e) => form.setData('due_on', e.target.value)}
                />
                <Button
                    size="sm"
                    variant="outline"
                    disabled={
                        form.processing || !form.data.label || !form.data.due_on
                    }
                >
                    Add date
                </Button>
            </form>
        </section>
    );
}
