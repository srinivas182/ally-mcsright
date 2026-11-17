import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Channel = {
    ulid: string;
    kind: string;
    name: string;
    unread: number;
    can_post: boolean;
    last_message_at: string | null;
};
type Message = {
    id: number;
    author: string;
    author_id: number;
    mine: boolean;
    body: string | null;
    hidden: boolean;
    edited: boolean;
    at: string;
    can_change: boolean;
    can_hide: boolean;
};
type Person = { id: number; name: string };

const kindLabel: Record<string, string> = {
    announcements: 'Broker news',
    everyone: 'Whole office',
    group: 'Group',
    direct: 'Direct',
};

/** Team chat (CH1): announcements, everyone, groups and direct messages, refreshed every 10 seconds. */
export default function TeamChat({
    channels,
    current,
    messages: initial,
    people,
    members,
    leads,
}: {
    channels: Channel[];
    current: Channel | null;
    messages: Message[];
    people: Person[];
    members: number[];
    leads: boolean;
}) {
    const [messages, setMessages] = useState<Message[]>(initial);
    const [mentions, setMentions] = useState<number[]>([]);
    const [picker, setPicker] = useState(false);
    const [newGroup, setNewGroup] = useState(false);
    const [groupName, setGroupName] = useState('');
    const [groupPeople, setGroupPeople] = useState<number[]>([]);
    const form = useForm({ body: '' });
    const bottom = useRef<HTMLDivElement>(null);

    useEffect(() => setMessages(initial), [initial]);
    useEffect(
        () => bottom.current?.scrollIntoView({ block: 'end' }),
        [messages],
    );
    useEffect(() => {
        if (!current) return;
        const timer = window.setInterval(async () => {
            const last = messages.at(-1)?.id ?? 0;
            try {
                const r = await fetch(
                    `/chat/${current.ulid}/messages?after=${last}`,
                    { headers: { Accept: 'application/json' } },
                );
                if (!r.ok) return;
                const data = (await r.json()) as { messages: Message[] };
                if (data.messages.length > 0)
                    setMessages((m) => [...m, ...data.messages]);
            } catch {
                // offline for a moment: try again on the next tick
            }
        }, 10000);
        return () => window.clearInterval(timer);
    }, [current, messages]);

    const mentionable = people.filter(
        (p) =>
            current?.kind === 'everyone' ||
            current?.kind === 'announcements' ||
            members.includes(p.id),
    );

    return (
        <>
            <Head title="Team chat" />
            <div className="mx-auto grid w-full max-w-6xl gap-4 p-4 md:p-6">
                <PageHeader
                    title="Team chat"
                    description="Talk with your office. Direct messages are private to the two of you."
                />
                <div className="grid gap-4 md:grid-cols-[16rem_1fr]">
                    <nav
                        aria-label="Chats"
                        className="grid content-start gap-1"
                    >
                        {channels.map((c) => (
                            <a
                                key={c.ulid}
                                href={`/chat?c=${c.ulid}`}
                                aria-current={
                                    current?.ulid === c.ulid
                                        ? 'page'
                                        : undefined
                                }
                                className={`flex items-center justify-between rounded px-3 py-2 text-base ${current?.ulid === c.ulid ? 'bg-muted font-semibold' : 'hover:bg-muted/50'}`}
                            >
                                <span>
                                    {c.name}
                                    <span className="block text-xs text-muted-foreground">
                                        {kindLabel[c.kind] ?? c.kind}
                                    </span>
                                </span>
                                {c.unread > 0 && (
                                    <Badge aria-label={`${c.unread} unread`}>
                                        {c.unread}
                                    </Badge>
                                )}
                            </a>
                        ))}
                        <label
                            className="mt-3 text-sm font-medium"
                            htmlFor="dm"
                        >
                            New direct message
                        </label>
                        <select
                            id="dm"
                            className="rounded border px-2 py-2"
                            defaultValue=""
                            onChange={(e) =>
                                e.target.value &&
                                router.post('/chat/direct', {
                                    user: Number(e.target.value),
                                })
                            }
                        >
                            <option value="">Choose a person…</option>
                            {people.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                        {leads && (
                            <div className="mt-3 grid gap-2">
                                {!newGroup ? (
                                    <Button
                                        variant="outline"
                                        onClick={() => setNewGroup(true)}
                                    >
                                        New group
                                    </Button>
                                ) : (
                                    <form
                                        className="grid gap-2 rounded border p-2"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            router.post('/chat/groups', {
                                                name: groupName,
                                                members: groupPeople,
                                            });
                                        }}
                                    >
                                        <input
                                            aria-label="Group name"
                                            placeholder="e.g. Downtown office"
                                            className="rounded border px-2 py-1"
                                            value={groupName}
                                            onChange={(e) =>
                                                setGroupName(e.target.value)
                                            }
                                        />
                                        <div className="max-h-48 overflow-y-auto">
                                            {people.map((p) => (
                                                <label
                                                    key={p.id}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={groupPeople.includes(
                                                            p.id,
                                                        )}
                                                        onChange={(e) =>
                                                            setGroupPeople(
                                                                e.target.checked
                                                                    ? [
                                                                          ...groupPeople,
                                                                          p.id,
                                                                      ]
                                                                    : groupPeople.filter(
                                                                          (x) =>
                                                                              x !==
                                                                              p.id,
                                                                      ),
                                                            )
                                                        }
                                                    />
                                                    {p.name}
                                                </label>
                                            ))}
                                        </div>
                                        <Button
                                            disabled={
                                                !groupName ||
                                                groupPeople.length === 0
                                            }
                                        >
                                            Make group
                                        </Button>
                                    </form>
                                )}
                            </div>
                        )}
                    </nav>
                    <section
                        aria-label={current?.name ?? 'Chat'}
                        className="grid min-h-[60vh] grid-rows-[1fr_auto] rounded-lg border"
                    >
                        <div
                            className="grid content-start gap-3 overflow-y-auto p-4"
                            aria-live="polite"
                        >
                            {messages.length === 0 && (
                                <p className="text-muted-foreground">
                                    No messages yet. Say hello!
                                </p>
                            )}
                            {messages.map((m) => (
                                <article key={m.id} className="grid gap-0.5">
                                    <p className="text-sm text-muted-foreground">
                                        <strong className="text-foreground">
                                            {m.author}
                                        </strong>{' '}
                                        ·{' '}
                                        {new Date(m.at).toLocaleString([], {
                                            dateStyle: 'medium',
                                            timeStyle: 'short',
                                        })}
                                        {m.edited && ' · edited'}
                                    </p>
                                    {m.hidden ? (
                                        <p className="text-sm text-muted-foreground italic">
                                            This message was removed.
                                        </p>
                                    ) : (
                                        <p className="text-base whitespace-pre-wrap">
                                            {m.body}
                                        </p>
                                    )}
                                    {(m.can_change || m.can_hide) && (
                                        <span className="flex gap-3 text-xs">
                                            {m.can_change && (
                                                <button
                                                    type="button"
                                                    className="underline"
                                                    onClick={() => {
                                                        const body =
                                                            window.prompt(
                                                                'Change your message',
                                                                m.body ?? '',
                                                            );
                                                        if (body)
                                                            router.patch(
                                                                `/chat/messages/${m.id}`,
                                                                { body },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                    }}
                                                >
                                                    Change
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                className="underline"
                                                onClick={() =>
                                                    router.post(
                                                        `/chat/messages/${m.id}/hide`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {m.mine
                                                    ? 'Take back'
                                                    : 'Remove'}
                                            </button>
                                        </span>
                                    )}
                                </article>
                            ))}
                            <div ref={bottom} />
                        </div>
                        {current?.can_post ? (
                            <form
                                className="grid gap-2 border-t p-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.transform((d) => ({ ...d, mentions }));
                                    form.post(
                                        `/chat/${current.ulid}/messages`,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                form.reset();
                                                setMentions([]);
                                            },
                                        },
                                    );
                                }}
                            >
                                <textarea
                                    aria-label="Message"
                                    rows={3}
                                    className="rounded border px-3 py-2 text-base"
                                    placeholder="Write a message…"
                                    value={form.data.body}
                                    onChange={(e) => {
                                        form.setData('body', e.target.value);
                                        if (e.target.value.endsWith('@'))
                                            setPicker(true);
                                    }}
                                />
                                {form.errors.body && (
                                    <p
                                        role="alert"
                                        className="text-sm text-red-700"
                                    >
                                        {form.errors.body}
                                    </p>
                                )}
                                {picker && (
                                    <select
                                        aria-label="Mention someone"
                                        className="rounded border px-2 py-1"
                                        defaultValue=""
                                        onChange={(e) => {
                                            const p = mentionable.find(
                                                (x) =>
                                                    x.id ===
                                                    Number(e.target.value),
                                            );
                                            if (p) {
                                                form.setData(
                                                    'body',
                                                    form.data.body.replace(
                                                        /@$/,
                                                        '',
                                                    ) + `@${p.name} `,
                                                );
                                                setMentions([
                                                    ...mentions,
                                                    p.id,
                                                ]);
                                            }
                                            setPicker(false);
                                        }}
                                    >
                                        <option value="">Mention who?</option>
                                        {mentionable.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </select>
                                )}
                                <span className="flex items-center gap-2">
                                    <Button
                                        disabled={
                                            form.processing || !form.data.body
                                        }
                                    >
                                        Send
                                    </Button>
                                    <span className="text-xs text-muted-foreground">
                                        Type @ to mention someone. They get an
                                        email if it stays unread for 10 minutes.
                                    </span>
                                </span>
                            </form>
                        ) : (
                            <p className="border-t p-3 text-sm text-muted-foreground">
                                Only the broker and staff post here.
                            </p>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}
