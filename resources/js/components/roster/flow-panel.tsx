import { router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

export type Flow = {
    ulid: string;
    status: string;
    leaving_on: string | null;
    reason: string | null;
    progress: number;
    tasks: {
        id: number;
        title: string;
        who: string;
        automatic: boolean;
        done: boolean;
    }[];
};

/** An onboarding or offboarding checklist with progress. */
export function FlowPanel({
    title,
    flow,
    canManage,
    onComplete,
}: {
    title: string;
    flow: Flow;
    canManage: boolean;
    onComplete?: () => void;
}) {
    return (
        <section className="grid gap-2 rounded-lg border p-4 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-semibold">
                    {title}
                    {flow.leaving_on
                        ? ` — leaving ${new Date(flow.leaving_on).toLocaleDateString()}`
                        : ''}
                </h2>
                <Badge
                    variant={
                        flow.status === 'complete' ? 'secondary' : 'outline'
                    }
                >
                    {flow.status === 'complete'
                        ? 'Complete'
                        : `${flow.progress}% done`}
                </Badge>
            </div>
            <div
                className="h-2 overflow-hidden rounded bg-muted"
                role="progressbar"
                aria-valuenow={flow.progress}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <div
                    className="h-full bg-primary"
                    style={{ width: `${flow.progress}%` }}
                />
            </div>
            {flow.reason && (
                <p className="text-muted-foreground">Reason: {flow.reason}</p>
            )}
            <ul className="grid gap-1">
                {flow.tasks.map((t) => (
                    <li key={t.id} className="flex items-center gap-2">
                        {canManage && !t.automatic && flow.status === 'open' ? (
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={t.done}
                                aria-label={t.title}
                                onChange={(e) =>
                                    router.post(
                                        `/roster/tasks/${t.id}`,
                                        { done: e.target.checked },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                        ) : (
                            <span
                                aria-hidden
                                className={
                                    t.done
                                        ? 'text-emerald-600'
                                        : 'text-muted-foreground'
                                }
                            >
                                {t.done ? '✓' : '○'}
                            </span>
                        )}
                        <span
                            className={
                                t.done
                                    ? 'text-muted-foreground line-through'
                                    : ''
                            }
                        >
                            {t.title}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {t.who === 'agent'
                                ? t.automatic
                                    ? 'agent · ticks itself'
                                    : 'agent'
                                : 'brokerage'}
                        </span>
                    </li>
                ))}
            </ul>
            {onComplete && flow.status === 'open' && (
                <Button
                    size="sm"
                    className="justify-self-start"
                    disabled={flow.progress < 100}
                    onClick={onComplete}
                >
                    Complete
                </Button>
            )}
        </section>
    );
}
