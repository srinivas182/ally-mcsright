import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Message = {
    id: number;
    role: 'visitor' | 'ailly' | 'agent' | 'note';
    body: string;
    at: string;
    by: string | null;
};
type Chat = {
    ulid: string;
    page: string;
    outcome: string;
    score: number | null;
    live: boolean;
    taken_by: { id: number; name: string } | null;
    mine: boolean;
    can_take: boolean;
    messages: Message[];
};

const who = (m: Message) =>
    m.role === 'visitor'
        ? 'Visitor'
        : m.role === 'agent'
          ? (m.by ?? 'Agent')
          : 'Assistant';

/**
 * One website chat: read it as it happens (refreshes every 3 seconds), join it, reply, and hand it back to the
 * assistant.
 */
export default function AillyChat({ chat }: { chat: Chat }) {
    usePoll(3000, { only: ['chat'] });
    const form = useForm({ body: '' });
    const end = useRef<HTMLDivElement>(null);
    const base = `/ailly/chats/${chat.ulid}`;

    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
    }, [chat.messages.length]);

    return (
        <>
            <Head title="Website chat" />
            <div className="grid max-w-3xl gap-4 p-4">
                <PageHeader
                    title="Website chat"
                    description={`On ${chat.page || '/'}${chat.score !== null ? ` · lead score ${chat.score}` : ''}`}
                    actions={
                        <Link
                            href="/ailly/chats"
                            className="text-sm underline-offset-4 hover:underline"
                        >
                            All live chats
                        </Link>
                    }
                />
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    {chat.live ? (
                        <Badge>Visitor is on the website</Badge>
                    ) : (
                        <Badge variant="outline">Visitor has gone quiet</Badge>
                    )}
                    {chat.taken_by ? (
                        <span>
                            {chat.mine ? 'You are' : `${chat.taken_by.name} is`}{' '}
                            in the chat; the assistant is quiet.
                        </span>
                    ) : (
                        <span className="text-muted-foreground">
                            The assistant is answering.
                        </span>
                    )}
                </div>

                <div
                    className="grid max-h-[60vh] gap-2 overflow-y-auto rounded-lg border p-3 text-sm"
                    aria-live="polite"
                >
                    {chat.messages.map((m) =>
                        m.role === 'note' ? (
                            <p
                                key={m.id}
                                className="text-center text-xs text-muted-foreground italic"
                            >
                                {m.body}
                            </p>
                        ) : (
                            <div
                                key={m.id}
                                className={
                                    m.role === 'visitor'
                                        ? 'justify-self-start rounded-lg bg-muted px-3 py-2'
                                        : 'justify-self-end rounded-lg border px-3 py-2'
                                }
                            >
                                <span className="block text-xs text-muted-foreground">
                                    {who(m)} ·{' '}
                                    {new Date(m.at).toLocaleTimeString()}
                                </span>
                                {m.body}
                            </div>
                        ),
                    )}
                    <div ref={end} />
                </div>

                {chat.mine ? (
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`${base}/say`, {
                                preserveScroll: true,
                                onSuccess: () => form.reset('body'),
                            });
                        }}
                    >
                        <label className="sr-only" htmlFor="ailly-reply">
                            Your reply
                        </label>
                        <Textarea
                            id="ailly-reply"
                            rows={3}
                            maxLength={1000}
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && !e.shiftKey) {
                                    e.preventDefault();
                                    e.currentTarget.form?.requestSubmit();
                                }
                            }}
                            placeholder="Write to the visitor… (Enter to send)"
                        />
                        {form.errors.body && (
                            <p className="text-sm text-destructive">
                                {form.errors.body}
                            </p>
                        )}
                        <div className="flex gap-2">
                            <Button disabled={form.processing}>Send</Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `${base}/hand-back`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Hand back to the assistant
                            </Button>
                        </div>
                    </form>
                ) : chat.can_take ? (
                    <div>
                        <Button
                            onClick={() =>
                                router.post(
                                    `${base}/take`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {chat.taken_by
                                ? 'Take over from ' + chat.taken_by.name
                                : 'Join the chat'}
                        </Button>
                        <p className="mt-2 text-sm text-muted-foreground">
                            The visitor sees your first name. Don't share
                            financial or personal details in the chat.
                        </p>
                    </div>
                ) : null}
            </div>
        </>
    );
}

AillyChat.layout = {
    breadcrumbs: [
        { title: 'Live website chats', href: '/ailly/chats' },
        { title: 'Chat', href: '#' },
    ],
};
