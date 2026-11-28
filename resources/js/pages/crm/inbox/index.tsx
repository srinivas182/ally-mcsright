import { Head, Link, router, useForm } from '@inertiajs/react';
import { Mail, MessageSquare, Phone, Search } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { postJson } from '@/lib/http';

type Conversation = {
    ulid: string;
    name: string;
    preview: string | null;
    at: string | null;
    unread: boolean;
    needs_reply: boolean;
    waiting_since: string | null;
    agent: string | null;
    last_in: boolean;
};
type Msg = {
    ulid: string;
    channel: 'sms' | 'email';
    direction: 'in' | 'out';
    subject: string | null;
    body: string;
    status: string;
    at: string;
    ai: boolean;
    by: string | null;
};
type Current = {
    ulid: string;
    contact: {
        ulid: string;
        name: string;
        phone: string | null;
        email: string | null;
        lifecycle: string;
        source: string | null;
    };
    agent: string | null;
    messages: Msg[];
    nurture: {
        status: string;
        touches: number;
        next_at: string | null;
        ends_on: string;
        last_reason: string | null;
        stopped_reason: string | null;
    } | null;
    ai: {
        status: string;
        reason: string | null;
        summary: string | null;
        next_message: string | null;
        qualification: Record<string, string>;
        messages: number;
    } | null;
    blocks: { sms: string[]; email: string[] };
    templates: {
        ulid: string;
        name: string;
        channel: string;
        subject: string | null;
        body: string;
    }[];
};
type Props = {
    conversations: Conversation[];
    filter: string;
    current: Current | null;
    templates: {
        ulid: string;
        name: string;
        channel: string;
        subject: string | null;
        body: string;
        shared: boolean;
        mine: boolean;
    }[];
    fields: string[];
    signCodes: {
        ulid: string;
        code: string;
        property: string;
        details: string;
        active: boolean;
        texts: number;
    }[];
    signNumber: string | null;
    canManage: boolean;
};

const filters = [
    ['all', 'All'],
    ['needs_reply', 'Needs reply'],
    ['unread', 'Unread'],
    ['mine', 'Mine'],
] as const;

const time = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

/** Texts and emails with every contact, both ways, in one place; every send is checked first. */
const initials = (name: string) =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join('');

function Avatar({ name, size = 'md' }: { name: string; size?: 'sm' | 'md' }) {
    return (
        <span
            aria-hidden="true"
            className={`grid shrink-0 place-items-center rounded-full bg-primary/10 font-semibold text-primary ${size === 'sm' ? 'size-9 text-sm' : 'size-11 text-base'}`}
        >
            {initials(name) || '?'}
        </span>
    );
}

const ago = (iso: string) => {
    const mins = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );
    if (mins < 60) {
        return `${mins} min`;
    }
    const h = Math.round(mins / 60);

    return h < 48 ? `${h} h` : `${Math.round(h / 24)} days`;
};

/** +15125550142 → (512) 555-0142; anything else as it is. */
const phone = (p: string) => {
    const m = /^\+1(\d{3})(\d{3})(\d{4})$/.exec(p);

    return m ? `(${m[1]}) ${m[2]}-${m[3]}` : p;
};

/** Lead sources in the words people use (realtor_com → Realtor.com). */
const SOURCES: Record<string, string> = {
    zillow: 'Zillow',
    realtor_com: 'Realtor.com',
    homes_com: 'Homes.com',
    open_house: 'open house',
};
const sourceLabel = (s: string) => SOURCES[s] ?? s.replaceAll('_', ' ');

const shortTime = (iso: string) => {
    const d = new Date(iso);
    const today = new Date();

    return d.toDateString() === today.toDateString()
        ? d.toLocaleTimeString(undefined, {
              hour: 'numeric',
              minute: '2-digit',
          })
        : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
};

/**
 * Texts and emails with every contact, both ways, in one place; every send is checked first. FX6: laid out like a
 * mail app: who wrote last and when on the left, and on the right a header that says exactly who this is, whose
 * lead they are and how to reach them, each message labelled with who sent it, and a reply box that says to whom
 * and how the reply goes.
 */
export default function Inbox({
    conversations,
    filter,
    current,
    templates,
    fields,
    signCodes,
    signNumber,
    canManage,
}: Props) {
    const opts = { preserveScroll: true };
    const [q, setQ] = useState('');
    const go = (params: Record<string, string>) =>
        router.get(
            '/inbox',
            { filter, ...(current ? { c: current.ulid } : {}), ...params },
            { preserveState: true, preserveScroll: true },
        );
    const shown = conversations.filter((c) =>
        q.trim() === ''
            ? true
            : `${c.name} ${c.preview ?? ''} ${c.agent ?? ''}`
                  .toLowerCase()
                  .includes(q.trim().toLowerCase()),
    );

    return (
        <>
            <Head title="Inbox" />
            <div className="mx-auto grid w-full max-w-7xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Inbox"
                    description="Texts and emails with your contacts, both ways. Replies stop follow-up plans and show here at once."
                />
                <div className="grid overflow-hidden rounded-xl border bg-card shadow-xs md:h-[calc(100vh-15rem)] md:min-h-[32rem] md:grid-cols-[22rem_1fr]">
                    <aside
                        aria-label="Conversations"
                        className={`grid min-h-0 grid-rows-[auto_auto_1fr] border-b md:border-r md:border-b-0 ${current ? 'hidden md:grid' : ''}`}
                    >
                        <div className="border-b p-3">
                            <label className="relative block">
                                <span className="sr-only">
                                    Search conversations
                                </span>
                                <Search
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <Input
                                    className="h-10 pl-9"
                                    placeholder="Search by name or message"
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                />
                            </label>
                        </div>
                        <div
                            className="flex gap-1 border-b px-3 py-2"
                            role="tablist"
                            aria-label="Show"
                        >
                            {filters.map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={filter === key}
                                    className={`rounded-full px-3 py-1.5 text-sm font-medium transition-colors ${
                                        filter === key
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:bg-muted'
                                    }`}
                                    onClick={() => go({ filter: key })}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        {shown.length === 0 ? (
                            <p className="p-6 text-center text-sm text-muted-foreground">
                                {q.trim() !== ''
                                    ? 'No conversation matches that.'
                                    : filter === 'needs_reply'
                                      ? 'Nobody is waiting for a reply. Well done.'
                                      : 'No conversations here yet. Open a contact and choose Message to start one.'}
                            </p>
                        ) : (
                            <ul className="min-h-0 divide-y overflow-y-auto">
                                {shown.map((c) => {
                                    const on = current?.ulid === c.ulid;

                                    return (
                                        <li key={c.ulid}>
                                            <button
                                                type="button"
                                                aria-current={
                                                    on ? 'true' : undefined
                                                }
                                                className={`flex w-full gap-3 px-3 py-3 text-left transition-colors ${
                                                    on
                                                        ? 'bg-primary/10'
                                                        : 'hover:bg-muted/60'
                                                }`}
                                                onClick={() =>
                                                    go({ c: c.ulid })
                                                }
                                            >
                                                <Avatar
                                                    name={c.name}
                                                    size="sm"
                                                />
                                                <span className="grid min-w-0 flex-1 gap-0.5">
                                                    <span className="flex items-baseline gap-2">
                                                        <span
                                                            className={`truncate ${c.unread ? 'font-bold' : 'font-medium'}`}
                                                        >
                                                            {c.name}
                                                        </span>
                                                        <span
                                                            className={`ml-auto shrink-0 text-xs ${c.unread ? 'font-semibold text-primary' : 'text-muted-foreground'}`}
                                                        >
                                                            {c.at
                                                                ? shortTime(
                                                                      c.at,
                                                                  )
                                                                : ''}
                                                        </span>
                                                    </span>
                                                    <span
                                                        className={`truncate text-sm ${c.unread ? 'text-foreground' : 'text-muted-foreground'}`}
                                                    >
                                                        {c.last_in
                                                            ? ''
                                                            : 'You: '}
                                                        {c.preview}
                                                    </span>
                                                    {(c.needs_reply ||
                                                        (canManage &&
                                                            c.agent)) && (
                                                        <span className="flex flex-wrap items-center gap-2 text-xs">
                                                            {c.needs_reply &&
                                                                c.waiting_since && (
                                                                    <Badge variant="destructive">
                                                                        Waiting{' '}
                                                                        {ago(
                                                                            c.waiting_since,
                                                                        )}
                                                                    </Badge>
                                                                )}
                                                            {canManage &&
                                                                c.agent && (
                                                                    <span className="text-muted-foreground">
                                                                        {
                                                                            c.agent
                                                                        }
                                                                        &apos;s
                                                                        contact
                                                                    </span>
                                                                )}
                                                        </span>
                                                    )}
                                                </span>
                                                {c.unread && (
                                                    <span
                                                        className="mt-2 size-2.5 shrink-0 rounded-full bg-primary"
                                                        aria-label="Unread"
                                                    />
                                                )}
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </aside>
                    <section
                        className={`min-h-0 min-w-0 ${current ? '' : 'hidden md:block'}`}
                    >
                        {current ? (
                            <Thread
                                key={current.ulid}
                                current={current}
                                opts={opts}
                                onBack={() =>
                                    router.get(
                                        '/inbox',
                                        { filter },
                                        { preserveState: true },
                                    )
                                }
                            />
                        ) : (
                            <div className="grid h-full place-items-center p-8">
                                <EmptyState
                                    title="Choose a conversation"
                                    description="Pick someone on the left to read and reply. Or open a contact and choose Message to start one."
                                />
                            </div>
                        )}
                    </section>
                </div>

                <h2 className="mt-2 text-lg font-semibold">Message tools</h2>
                <TemplatesPanel
                    templates={templates}
                    fields={fields}
                    canManage={canManage}
                />
                <SignCodesPanel signCodes={signCodes} signNumber={signNumber} />
            </div>
        </>
    );
}

function Thread({
    current,
    opts,
    onBack,
}: {
    current: Current;
    opts: { preserveScroll: boolean };
    onBack: () => void;
}) {
    const canSms = current.blocks.sms.length === 0;
    const form = useForm({
        channel: (canSms || current.blocks.email.length > 0
            ? 'sms'
            : 'email') as 'sms' | 'email',
        subject: '',
        body: '',
        ai_drafted: false,
    });
    const [drafting, setDrafting] = useState(false);
    const [note, setNote] = useState<string | null>(null);
    const blocks = current.blocks[form.data.channel];
    const first = current.contact.name.split(' ')[0] || current.contact.name;
    const to =
        form.data.channel === 'sms'
            ? current.contact.phone && phone(current.contact.phone)
            : current.contact.email;
    const draft = async () => {
        setDrafting(true);
        setNote(null);
        try {
            const r = await postJson<{ draft: string; warnings: string[] }>(
                `/inbox/${current.ulid}/draft`,
                { channel: form.data.channel },
            );
            form.setData({ ...form.data, body: r.draft, ai_drafted: true });
            if (r.warnings.length) {
                setNote(`Check the wording: ${r.warnings.join(', ')}`);
            }
        } catch (e) {
            setNote(
                e instanceof Error
                    ? e.message
                    : 'The AI assistant is unavailable.',
            );
        } finally {
            setDrafting(false);
        }
    };
    let lastDay = '';

    return (
        <div className="grid h-full min-h-0 grid-rows-[auto_auto_1fr_auto] text-sm">
            <header className="flex flex-wrap items-center gap-3 border-b px-4 py-3">
                <button
                    type="button"
                    className="text-sm font-medium text-primary md:hidden"
                    onClick={onBack}
                >
                    ‹ All
                </button>
                <Avatar name={current.contact.name} />
                <div className="grid min-w-0 flex-1 gap-0.5">
                    <p className="flex flex-wrap items-center gap-2">
                        <Link
                            className="text-lg font-semibold hover:underline"
                            href={`/contacts/${current.contact.ulid}`}
                        >
                            {current.contact.name}
                        </Link>
                        <Badge variant="secondary">
                            {current.contact.lifecycle}
                        </Badge>
                    </p>
                    <p className="flex flex-wrap gap-x-3 gap-y-0.5 text-muted-foreground">
                        {current.contact.phone && (
                            <span className="inline-flex items-center gap-1">
                                <Phone
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                                {phone(current.contact.phone)}
                            </span>
                        )}
                        {current.contact.email && (
                            <span className="inline-flex items-center gap-1">
                                <Mail className="size-3.5" aria-hidden="true" />
                                {current.contact.email}
                            </span>
                        )}
                        {current.agent && <span>Agent: {current.agent}</span>}
                        {current.contact.source && (
                            <span>
                                From: {sourceLabel(current.contact.source)}
                            </span>
                        )}
                    </p>
                </div>
                <span className="flex gap-2">
                    {current.contact.phone && (
                        <Button asChild size="sm" variant="outline">
                            <a href={`tel:${current.contact.phone}`}>
                                <Phone aria-hidden="true" /> Call
                            </a>
                        </Button>
                    )}
                    <Button asChild size="sm" variant="outline">
                        <Link href={`/contacts/${current.contact.ulid}`}>
                            Open contact
                        </Link>
                    </Button>
                </span>
            </header>
            <div className="grid gap-2 empty:hidden">
                {current.ai && (
                    <div className="mx-4 mt-3 grid gap-1 rounded-lg border border-primary/40 bg-primary/5 p-3">
                        <p className="font-medium">
                            AI assistant:{' '}
                            {current.ai.status === 'active'
                                ? `talking to them (${current.ai.messages} sent). Write to them yourself to take over.`
                                : `handed over${current.ai.reason ? ` (${current.ai.reason.replaceAll('_', ' ')})` : ''}.`}
                        </p>
                        {current.ai.summary && <p>{current.ai.summary}</p>}
                        {Object.keys(current.ai.qualification).length > 0 && (
                            <ul className="flex flex-wrap gap-1">
                                {Object.entries(current.ai.qualification).map(
                                    ([k, v]) => (
                                        <li key={k}>
                                            <Badge variant="outline">
                                                {k.replaceAll('_', ' ')}: {v}
                                            </Badge>
                                        </li>
                                    ),
                                )}
                            </ul>
                        )}
                        {current.ai.next_message &&
                            current.ai.status !== 'active' && (
                                <p className="text-muted-foreground">
                                    Suggested next message: “
                                    {current.ai.next_message}”
                                </p>
                            )}
                    </div>
                )}
                {current.nurture && (
                    <div className="mx-4 mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3">
                        <p>
                            <span className="font-medium">
                                Long follow-up:{' '}
                            </span>
                            {current.nurture.status === 'active'
                                ? `${current.nurture.touches} sent so far, next ${current.nurture.next_at ? time(current.nurture.next_at) : 'soon'}. Runs until ${current.nurture.ends_on} or until they reply.`
                                : current.nurture.status === 'replied'
                                  ? 'they replied. Over to you.'
                                  : current.nurture.status === 'done'
                                    ? 'finished after 12 months.'
                                    : `stopped${current.nurture.stopped_reason ? ` (${current.nurture.stopped_reason.replaceAll('_', ' ')})` : ''}.`}
                        </p>
                        {current.nurture.status === 'active' && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/inbox/${current.ulid}/nurture/stop`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Stop follow-up
                            </Button>
                        )}
                    </div>
                )}
            </div>
            <ol
                className="flex min-h-48 flex-col gap-3 overflow-y-auto bg-muted/30 px-4 py-4"
                aria-label={`Messages with ${current.contact.name}`}
            >
                {current.messages.length === 0 && (
                    <li className="m-auto text-muted-foreground">
                        No messages yet. Write the first one below.
                    </li>
                )}
                {current.messages.map((m) => {
                    const day = new Date(m.at).toLocaleDateString(undefined, {
                        weekday: 'long',
                        month: 'short',
                        day: 'numeric',
                    });
                    const showDay = day !== lastDay;
                    lastDay = day;
                    const out = m.direction === 'out';
                    const who = out
                        ? m.by
                            ? m.ai
                                ? `${m.by} (AI-drafted)`
                                : m.by
                            : m.ai
                              ? 'AI assistant'
                              : 'Sent automatically'
                        : current.contact.name;

                    return (
                        <li key={m.ulid} className="grid gap-1">
                            {showDay && (
                                <p className="my-1 text-center text-xs font-medium text-muted-foreground">
                                    {day}
                                </p>
                            )}
                            <div
                                className={`grid max-w-[80%] gap-1 ${out ? 'justify-self-end' : 'justify-self-start'}`}
                            >
                                <p
                                    className={`flex items-center gap-1.5 text-xs text-muted-foreground ${out ? 'justify-end' : ''}`}
                                >
                                    {m.channel === 'sms' ? (
                                        <MessageSquare
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <Mail
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                    )}
                                    <span className="font-medium text-foreground">
                                        {who}
                                    </span>
                                    <span>
                                        {m.channel === 'sms' ? 'Text' : 'Email'}{' '}
                                        ·{' '}
                                        {new Date(m.at).toLocaleTimeString(
                                            undefined,
                                            {
                                                hour: 'numeric',
                                                minute: '2-digit',
                                            },
                                        )}
                                    </span>
                                </p>
                                <div
                                    className={`rounded-2xl px-3.5 py-2.5 shadow-xs ${
                                        out
                                            ? 'rounded-br-sm bg-primary text-primary-foreground'
                                            : 'rounded-bl-sm border bg-background'
                                    }`}
                                >
                                    {m.subject && (
                                        <p className="mb-1 font-semibold">
                                            {m.subject}
                                        </p>
                                    )}
                                    <p className="whitespace-pre-wrap">
                                        {m.body}
                                    </p>
                                </div>
                                {m.status === 'failed' && (
                                    <p className="text-right text-xs text-destructive">
                                        Not delivered
                                    </p>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ol>
            <form
                className="grid gap-2 border-t bg-background p-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/inbox/${current.ulid}/send`, {
                        ...opts,
                        onSuccess: () =>
                            form.reset('body', 'subject', 'ai_drafted'),
                    });
                }}
            >
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">Reply to {first} by</span>
                    <div
                        className="inline-flex rounded-md border p-0.5"
                        role="radiogroup"
                        aria-label="Send as"
                    >
                        {(['sms', 'email'] as const).map((ch) => (
                            <button
                                key={ch}
                                type="button"
                                role="radio"
                                aria-checked={form.data.channel === ch}
                                className={`rounded px-3 py-1 text-sm font-medium ${
                                    form.data.channel === ch
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-muted'
                                }`}
                                onClick={() => form.setData('channel', ch)}
                            >
                                {ch === 'sms' ? 'Text' : 'Email'}
                            </button>
                        ))}
                    </div>
                    <span className="text-muted-foreground">
                        {to
                            ? `to ${to}`
                            : form.data.channel === 'sms'
                              ? 'no mobile number on file'
                              : 'no email on file'}
                    </span>
                    <span className="ml-auto flex flex-wrap gap-2">
                        <select
                            aria-label="Template"
                            className="h-8 rounded-md border bg-background px-2"
                            value=""
                            onChange={(e) => {
                                const t = current.templates.find(
                                    (x) => x.ulid === e.target.value,
                                );
                                if (t) {
                                    form.setData({
                                        ...form.data,
                                        body: t.body,
                                        subject: t.subject ?? form.data.subject,
                                        ai_drafted: false,
                                    });
                                }
                            }}
                        >
                            <option value="">Use a template…</option>
                            {current.templates
                                .filter((t) => t.channel === form.data.channel)
                                .map((t) => (
                                    <option key={t.ulid} value={t.ulid}>
                                        {t.name}
                                    </option>
                                ))}
                        </select>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={drafting}
                            onClick={draft}
                        >
                            {drafting ? 'Drafting…' : 'Suggest a reply'}
                        </Button>
                    </span>
                </div>
                {form.data.channel === 'email' && (
                    <Input
                        aria-label="Subject"
                        placeholder="Subject"
                        value={form.data.subject}
                        onChange={(e) =>
                            form.setData('subject', e.target.value)
                        }
                    />
                )}
                <textarea
                    aria-label={`Message to ${current.contact.name}`}
                    placeholder={`Write to ${first}…`}
                    className="min-h-20 rounded-md border bg-background p-3 outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                />
                {blocks.length > 0 && (
                    <ul
                        role="alert"
                        className="grid gap-1 rounded border border-destructive/50 p-2 text-destructive"
                    >
                        {blocks.map((b) => (
                            <li key={b}>{b}</li>
                        ))}
                    </ul>
                )}
                {note && <p className="text-amber-600">{note}</p>}
                {form.errors.body && (
                    <p role="alert" className="text-destructive">
                        {form.errors.body}
                    </p>
                )}
                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        disabled={
                            form.processing ||
                            blocks.length > 0 ||
                            form.data.body.trim() === ''
                        }
                    >
                        Send {form.data.channel === 'sms' ? 'text' : 'email'}
                    </Button>
                    {form.data.channel === 'sms' && (
                        <span className="text-xs text-muted-foreground">
                            {form.data.body.length} characters
                        </span>
                    )}
                    {form.data.ai_drafted && (
                        <span className="text-xs text-muted-foreground">
                            AI suggested this reply. Read and edit it before you
                            send.
                        </span>
                    )}
                </div>
            </form>
        </div>
    );
}

function TemplatesPanel({
    templates,
    fields,
    canManage,
}: {
    templates: Props['templates'];
    fields: string[];
    canManage: boolean;
}) {
    const form = useForm({
        name: '',
        channel: 'sms',
        subject: '',
        body: '',
        shared: false,
    });

    return (
        <details className="rounded-lg border p-3 text-sm">
            <summary className="font-semibold">
                Templates ({templates.length})
            </summary>
            <ul className="mt-2 divide-y">
                {templates.map((t) => (
                    <li
                        key={t.ulid}
                        className="flex flex-wrap items-center gap-2 py-2"
                    >
                        <span className="font-medium">{t.name}</span>
                        <Badge variant="outline">
                            {t.channel === 'sms' ? 'Text' : 'Email'}
                        </Badge>
                        {t.shared && <Badge variant="outline">Shared</Badge>}
                        <span className="min-w-0 flex-1 truncate text-muted-foreground">
                            {t.body}
                        </span>
                        {(t.mine || (t.shared && canManage)) && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(
                                        `/inbox/templates/${t.ulid}`,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Delete
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
            <form
                className="mt-2 grid gap-2 md:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/inbox/templates', {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <Input
                    aria-label="Template name"
                    placeholder="Name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <select
                    aria-label="Template type"
                    className="rounded border bg-background px-2 py-1.5"
                    value={form.data.channel}
                    onChange={(e) => form.setData('channel', e.target.value)}
                >
                    <option value="sms">Text</option>
                    <option value="email">Email</option>
                </select>
                {form.data.channel === 'email' && (
                    <Input
                        aria-label="Template subject"
                        placeholder="Subject"
                        className="md:col-span-2"
                        value={form.data.subject}
                        onChange={(e) =>
                            form.setData('subject', e.target.value)
                        }
                    />
                )}
                <textarea
                    aria-label="Template message"
                    className="rounded border bg-background p-2 md:col-span-2"
                    rows={3}
                    placeholder={`Hi {first_name}, …`}
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                />
                <p className="text-xs text-muted-foreground md:col-span-2">
                    Merge fields: {fields.map((f) => `{${f}}`).join(' ')}
                </p>
                {canManage && (
                    <label className="flex items-center gap-2">
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
                <Button
                    className="justify-self-start"
                    disabled={form.processing}
                >
                    Save template
                </Button>
                {Object.values(form.errors)[0] && (
                    <p role="alert" className="text-destructive md:col-span-2">
                        {Object.values(form.errors)[0]}
                    </p>
                )}
            </form>
        </details>
    );
}

function SignCodesPanel({
    signCodes,
    signNumber,
}: {
    signCodes: Props['signCodes'];
    signNumber: string | null;
}) {
    const form = useForm({ code: '', property: '', details: '' });

    return (
        <details className="rounded-lg border p-3 text-sm">
            <summary className="font-semibold">
                Text-for-info sign codes ({signCodes.length})
            </summary>
            <p className="mt-2 text-muted-foreground">
                {signNumber
                    ? `Put "Text CODE to ${signNumber}" on yard signs and flyers. People get the home's details back at once and become your lead.`
                    : 'Sign codes need your brokerage’s own texting number. Ask RightAlly support to set one up.'}
            </p>
            <ul className="mt-2 divide-y">
                {signCodes.map((s) => (
                    <li
                        key={s.ulid}
                        className="flex flex-wrap items-center gap-2 py-2"
                    >
                        <code className="font-semibold">{s.code}</code>
                        <span>{s.property}</span>
                        <span className="text-muted-foreground">
                            {s.texts} texts
                        </span>
                        <Button
                            size="sm"
                            variant="ghost"
                            className="ml-auto"
                            onClick={() =>
                                router.post(
                                    `/inbox/signs/${s.ulid}/toggle`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {s.active ? 'Turn off' : 'Turn on'}
                        </Button>
                    </li>
                ))}
            </ul>
            <form
                className="mt-2 grid gap-2 md:grid-cols-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/inbox/signs', {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <Input
                    aria-label="Code"
                    placeholder="Code, e.g. OAK12"
                    value={form.data.code}
                    onChange={(e) =>
                        form.setData('code', e.target.value.toUpperCase())
                    }
                />
                <Input
                    aria-label="Property"
                    placeholder="Property address"
                    className="md:col-span-2"
                    value={form.data.property}
                    onChange={(e) => form.setData('property', e.target.value)}
                />
                <textarea
                    aria-label="Details to text back"
                    className="rounded border bg-background p-2 md:col-span-3"
                    rows={2}
                    placeholder="3 bed, 2 bath, $450,000. Photos: https://…"
                    value={form.data.details}
                    onChange={(e) => form.setData('details', e.target.value)}
                />
                <Button
                    className="justify-self-start"
                    disabled={form.processing}
                >
                    Add sign code
                </Button>
                {Object.values(form.errors)[0] && (
                    <p role="alert" className="text-destructive md:col-span-3">
                        {Object.values(form.errors)[0]}
                    </p>
                )}
            </form>
        </details>
    );
}
