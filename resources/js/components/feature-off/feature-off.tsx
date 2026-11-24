import { router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Check } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';

/**
 * PG4: a feature that is not switched on for this brokerage yet. Instead of a dead end, it says what the feature does
 * and lets the person ask RightAlly to switch it on (one open request per brokerage; asking again adds nothing).
 */
export function FeatureOff({
    feature,
    icon: Icon,
    title,
    does,
    points,
}: {
    /** Key the server knows (SupportTickets::SWITCH_ON). */
    feature:
        | 'studio'
        | 'social'
        | 'ads'
        | 'postcards'
        | 'voice'
        | 'property_lookup';
    icon: LucideIcon;
    /** e.g. "Postcards" */
    title: string;
    /** One sentence: what it does for the agent. */
    does: string;
    /** Two to four short benefits. */
    points: string[];
}) {
    const [sending, setSending] = useState(false);

    return (
        <section
            aria-labelledby={`off-${feature}`}
            className="grid gap-6 overflow-hidden rounded-2xl border bg-card p-6 shadow-xs md:grid-cols-[auto_1fr] md:p-8"
        >
            <div className="grid size-16 place-items-center rounded-2xl bg-primary/10 text-primary">
                <Icon aria-hidden="true" className="size-8" />
            </div>
            <div className="grid max-w-2xl gap-4">
                <div className="grid gap-1">
                    <p className="text-sm font-medium text-amber-700 dark:text-amber-300">
                        Not switched on for your brokerage yet
                    </p>
                    <h2 id={`off-${feature}`} className="text-xl font-semibold">
                        {title}
                    </h2>
                    <p className="text-base text-muted-foreground">{does}</p>
                </div>
                <ul className="grid gap-2 text-base">
                    {points.map((p) => (
                        <li key={p} className="flex gap-2">
                            <Check
                                aria-hidden="true"
                                className="mt-0.5 size-5 shrink-0 text-emerald-600"
                            />
                            {p}
                        </li>
                    ))}
                </ul>
                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        size="lg"
                        disabled={sending}
                        onClick={() => {
                            setSending(true);
                            router.post(
                                '/help/switch-on',
                                {
                                    feature,
                                    page: window.location.pathname,
                                },
                                {
                                    preserveScroll: true,
                                    onFinish: () => setSending(false),
                                },
                            );
                        }}
                    >
                        Ask RightAlly to switch it on
                    </Button>
                    <span className="text-sm text-muted-foreground">
                        RightAlly replies by email.
                    </span>
                </div>
            </div>
        </section>
    );
}
