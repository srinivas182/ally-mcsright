import { Link, router, useForm } from '@inertiajs/react';
import { BellIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export type TaskRow = {
    ulid: string;
    title: string;
    type: string;
    priority: 'normal' | 'high';
    due_at: string;
    overdue: boolean;
    completed_at: string | null;
    remind: boolean;
    contact: { ulid: string; name: string } | null;
};

const dueLabel = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

/** Tasks with one-tap complete and quick rescheduling. */
export function TaskList({
    tasks,
    showContact = true,
}: {
    tasks: TaskRow[];
    showContact?: boolean;
}) {
    return (
        <ul className="divide-y rounded-lg border">
            {tasks.map((t) => (
                <li
                    key={t.ulid}
                    className="flex flex-wrap items-center gap-3 p-3"
                >
                    <input
                        type="checkbox"
                        aria-label={`Mark "${t.title}" ${t.completed_at ? 'not done' : 'done'}`}
                        className="size-5 rounded border"
                        checked={t.completed_at !== null}
                        onChange={() =>
                            router.post(
                                `/tasks/${t.ulid}/${t.completed_at ? 'reopen' : 'complete'}`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    />
                    <span className="grid min-w-0 flex-1 gap-0.5">
                        <span
                            className={cn(
                                'font-medium',
                                t.completed_at &&
                                    'text-muted-foreground line-through',
                            )}
                        >
                            {t.title}
                        </span>
                        <span
                            className={cn(
                                'text-sm',
                                t.overdue
                                    ? 'text-destructive'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {t.overdue ? 'Overdue · ' : ''}
                            {dueLabel(t.due_at)}
                            {showContact && t.contact && (
                                <>
                                    {' · '}
                                    <Link
                                        href={`/contacts/${t.contact.ulid}`}
                                        className="text-primary hover:underline"
                                    >
                                        {t.contact.name}
                                    </Link>
                                </>
                            )}
                        </span>
                    </span>
                    {t.priority === 'high' && (
                        <Badge variant="destructive">High</Badge>
                    )}
                    {t.remind && (
                        <BellIcon
                            aria-label="Reminder set"
                            className="size-4 text-muted-foreground"
                        />
                    )}
                    {!t.completed_at && (
                        <span className="flex gap-1">
                            {(['tomorrow', 'next_week'] as const).map((due) => (
                                <Button
                                    key={due}
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        router.post(
                                            `/tasks/${t.ulid}/reschedule`,
                                            { due },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {due === 'tomorrow'
                                        ? 'Tomorrow'
                                        : 'Next week'}
                                </Button>
                            ))}
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

/** Add a task in one line: title, then Today / Tomorrow / Next week. */
export function AddTask({ contactUlid }: { contactUlid?: string }) {
    const form = useForm({
        title: '',
        due: 'tomorrow',
        remind: false,
        contact: contactUlid ?? '',
    });

    return (
        <form
            className="grid gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/tasks', {
                    preserveScroll: true,
                    onSuccess: () => form.reset('title'),
                });
            }}
        >
            <Input
                aria-label="New task"
                placeholder="Add a task, e.g. Call about the inspection"
                value={form.data.title}
                onChange={(e) => form.setData('title', e.target.value)}
            />
            {form.errors.title && (
                <p className="text-sm text-destructive">{form.errors.title}</p>
            )}
            <div className="flex flex-wrap items-center gap-2">
                {(['today', 'tomorrow', 'next_week'] as const).map((due) => (
                    <Button
                        key={due}
                        type="button"
                        size="sm"
                        variant={form.data.due === due ? 'default' : 'outline'}
                        onClick={() => form.setData('due', due)}
                    >
                        {due === 'today'
                            ? 'Today'
                            : due === 'tomorrow'
                              ? 'Tomorrow'
                              : 'Next week'}
                    </Button>
                ))}
                <label className="flex items-center gap-1 text-sm">
                    <input
                        type="checkbox"
                        className="size-4 rounded border"
                        checked={form.data.remind}
                        onChange={(e) =>
                            form.setData('remind', e.target.checked)
                        }
                    />
                    Remind me
                </label>
                <Button
                    type="submit"
                    size="sm"
                    className="ml-auto"
                    disabled={form.processing || form.data.title.trim() === ''}
                >
                    Add task
                </Button>
            </div>
        </form>
    );
}
