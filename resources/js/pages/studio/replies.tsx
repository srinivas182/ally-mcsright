import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

type Item = {
    ulid: string;
    channel: 'facebook' | 'instagram';
    kind: 'comment' | 'message';
    from: string | null;
    text: string;
    intent: string | null;
    draft: string | null;
    status: string;
    reason: string | null;
    lead: boolean;
    at: string;
    expires: string | null;
};

const statusLabel: Record<string, string> = {
    awaiting: 'Reply ready',
    sensitive: 'Needs you',
    sent: 'Sent',
    skipped: 'Skipped',
    expired: 'Too late to reply here',
    failed: "Didn't send",
};

function Row({ item }: { item: Item }) {
    const [text, setText] = useState(item.draft ?? '');
    const [error, setError] = useState<string | null>(null);
    const open = ['awaiting', 'sensitive', 'failed'].includes(item.status);

    return (
        <li className="grid gap-2 rounded-xl border p-3">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <Badge variant="outline">
                    {item.channel === 'instagram' ? 'Instagram' : 'Facebook'}{' '}
                    {item.kind}
                </Badge>
                <span className="font-medium">{item.from ?? 'Someone'}</span>
                <span className="text-muted-foreground">{item.at}</span>
                <Badge
                    variant={
                        item.status === 'sensitive' ? 'destructive' : 'outline'
                    }
                >
                    {statusLabel[item.status] ?? item.status}
                </Badge>
                {item.lead && <Badge>New lead</Badge>}
            </div>
            <p className="rounded-lg bg-muted p-2 text-sm">{item.text}</p>
            {item.reason && (
                <p className="text-sm text-muted-foreground">{item.reason}</p>
            )}
            {open ? (
                <>
                    <textarea
                        aria-label="Your reply"
                        className="min-h-20 rounded-md border bg-background p-2 text-sm"
                        maxLength={1000}
                        placeholder="Write your reply"
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                    />
                    {item.expires && (
                        <span className="text-xs text-muted-foreground">
                            Reply by {item.expires} (Facebook and Instagram
                            allow 24 hours for messages).
                        </span>
                    )}
                    {error && (
                        <span className="text-sm text-destructive">
                            {error}
                        </span>
                    )}
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            disabled={text.trim() === ''}
                            onClick={() =>
                                router.post(
                                    `/studio/replies/${item.ulid}/send`,
                                    { text },
                                    {
                                        preserveScroll: true,
                                        onError: (e) =>
                                            setError(
                                                Object.values(e).join(' '),
                                            ),
                                    },
                                )
                            }
                        >
                            Send
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/studio/replies/${item.ulid}/skip`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Skip
                        </Button>
                    </div>
                </>
            ) : (
                item.draft && (
                    <p className="text-sm">
                        <span className="text-muted-foreground">You: </span>
                        {item.draft}
                    </p>
                )
            )}
        </li>
    );
}

/**
 * Studio → Replies (specs/grow/13, SA5c): comments and messages on your Facebook Page and Instagram, each with a
 * drafted reply to send, change or skip. Anything sensitive is left to you.
 */
export default function StudioRepliesPage({
    ready,
    settings,
    items,
}: {
    ready: boolean;
    settings: { on: boolean; mode: 'approve' | 'auto' };
    items: Item[];
}) {
    const [mode, setMode] = useState(settings.mode);

    if (!ready) {
        return (
            <div className="p-4">
                <Head title="Replies" />
                <EmptyState
                    title="Replies come with Ally Studio"
                    description="Choose a Studio package that includes replies to comments and messages."
                />
                <div className="mt-4">
                    <Button asChild>
                        <Link href="/studio">See the packages</Link>
                    </Button>
                </div>
            </div>
        );
    }
    const save = (on: boolean) =>
        router.post('/studio/replies', { on, mode }, { preserveScroll: true });

    return (
        <>
            <Head title="Replies" />
            <div className="grid max-w-3xl gap-6 p-4 text-base">
                <PageHeader
                    title="Replies"
                    description="Comments and messages on your Facebook Page and Instagram, each with a reply drafted for you. Fair Housing checked; anything sensitive is left to you."
                />
                <section className="grid gap-3 rounded-xl border p-4">
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 font-medium">
                            How replies go out
                        </legend>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                className="size-5"
                                checked={mode === 'approve'}
                                onChange={() => setMode('approve')}
                            />
                            I approve every reply first
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                className="size-5"
                                checked={mode === 'auto'}
                                onChange={() => setMode('auto')}
                            />
                            Send thanks and simple questions on their own; I
                            approve the rest
                        </label>
                    </fieldset>
                    <div className="flex gap-2">
                        <Button onClick={() => save(true)}>
                            {settings.on ? 'Save' : 'Turn replies on'}
                        </Button>
                        {settings.on && (
                            <Button
                                variant="outline"
                                onClick={() => save(false)}
                            >
                                Turn off
                            </Button>
                        )}
                    </div>
                </section>
                {items.length === 0 ? (
                    <EmptyState
                        title={
                            settings.on ? 'Nothing waiting' : 'Replies are off'
                        }
                        description={
                            settings.on
                                ? 'New comments and messages show here within a minute.'
                                : 'Turn replies on to see comments and messages here.'
                        }
                    />
                ) : (
                    <ul className="grid gap-3">
                        {items.map((i) => (
                            <Row key={i.ulid} item={i} />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
