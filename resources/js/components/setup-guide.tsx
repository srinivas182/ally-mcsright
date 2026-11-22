import { Check, ChevronDown, Copy, ExternalLink } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

export type GuideStep = {
    title: string;
    /** What to do, in plain words. */
    body: React.ReactNode;
    /** Shown with a tick when the app knows this step is done. */
    done?: boolean;
};

/**
 * HQ3: a numbered "How to set this up" guide for pages that connect an outside service, written for people who are
 * not technical. It starts open until every step is done, and can be opened again at any time.
 */
export function SetupGuide({
    title = 'How to set this up',
    intro,
    steps,
    className,
}: {
    title?: string;
    intro?: React.ReactNode;
    steps: GuideStep[];
    className?: string;
}) {
    const known = steps.filter((s) => s.done !== undefined);
    const allDone = known.length > 0 && known.every((s) => s.done);
    const doneCount = known.filter((s) => s.done).length;

    return (
        <details
            open={!allDone}
            className={cn(
                'group rounded-xl border border-primary/25 bg-primary/5 p-4 text-sm',
                className,
            )}
        >
            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 font-semibold">
                <span>
                    {title}
                    {known.length > 0 && (
                        <span className="ml-2 font-normal text-muted-foreground">
                            {allDone
                                ? 'All done'
                                : `${doneCount} of ${known.length} done`}
                        </span>
                    )}
                </span>
                <ChevronDown
                    aria-hidden="true"
                    className="size-4 transition-transform group-open:rotate-180"
                />
            </summary>
            {intro && <p className="mt-2 text-muted-foreground">{intro}</p>}
            <ol className="mt-3 grid gap-3">
                {steps.map((s, i) => (
                    <li key={s.title} className="flex gap-3">
                        <span
                            aria-hidden="true"
                            className={cn(
                                'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                s.done
                                    ? 'bg-emerald-600 text-white'
                                    : 'bg-primary text-primary-foreground',
                            )}
                        >
                            {s.done ? <Check className="size-3.5" /> : i + 1}
                        </span>
                        <div className="grid min-w-0 gap-1">
                            <p className="font-medium">
                                {s.title}
                                {s.done && (
                                    <span className="sr-only"> (done)</span>
                                )}
                            </p>
                            <div className="grid gap-2 text-muted-foreground">
                                {s.body}
                            </div>
                        </div>
                    </li>
                ))}
            </ol>
        </details>
    );
}

/** A value to paste somewhere else, with a Copy button. */
export function CopyText({ value, label }: { value: string; label?: string }) {
    const [copied, setCopied] = React.useState(false);

    return (
        <span className="flex min-w-0 items-center gap-2">
            <code className="min-w-0 truncate rounded bg-background px-2 py-1 text-foreground ring-1 ring-border">
                {value}
            </code>
            <button
                type="button"
                className="inline-flex shrink-0 items-center gap-1 rounded-md border bg-background px-2 py-1 text-xs font-medium text-foreground hover:bg-muted"
                aria-label={`Copy ${label ?? value}`}
                onClick={() => {
                    void navigator.clipboard?.writeText(value).then(() => {
                        setCopied(true);
                        window.setTimeout(() => setCopied(false), 1500);
                    });
                }}
            >
                {copied ? (
                    <Check aria-hidden="true" className="size-3.5" />
                ) : (
                    <Copy aria-hidden="true" className="size-3.5" />
                )}
                {copied ? 'Copied' : 'Copy'}
            </button>
        </span>
    );
}

/** A link to an outside site, opened in a new tab. */
export function OutLink({
    href,
    children,
}: {
    href: string;
    children: React.ReactNode;
}) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noreferrer noopener"
            className="inline-flex items-center gap-1 font-medium text-primary underline underline-offset-2"
        >
            {children}
            <ExternalLink aria-hidden="true" className="size-3.5" />
        </a>
    );
}
