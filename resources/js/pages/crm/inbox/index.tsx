import { Head, Link, router, useForm } from '@inertiajs/react';
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
    };
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
    const go = (params: Record<string, string>) =>
        router.get(
            '/inbox',
            { filter, ...(current ? { c: current.ulid } : {}), ...params },
            { preserveState: true, preserveScroll: true },
        );

    return (
        <>
            <Head title="Inbox" />
            <div className="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Inbox"
                    description="Texts and emails with your contacts, both ways. Replies stop follow-up plans and show here at once."
                />
                <div className="grid gap-4 md:grid-cols-[18rem_1fr]">
                    <aside className="grid content-start gap-2">
                        <div className="flex flex-wrap gap-1" role="tablist">
                            {filters.map(([key, label]) => (
                                <Button
                                    key={key}
                                    size="sm"
                                    role="tab"
                                    aria-selected={filter === key}
                                    variant={
                                        filter === key ? 'default' : 'outline'
                                    }
                                    onClick={() => go({ filter: key })}
                                >
                                    {label}
                                </Button>
                            ))}
                        </div>
                        {conversations.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No conversations here.
                            </p>
                        ) : (
                            <ul className="divide-y rounded-lg border text-sm">
                                {conversations.map((c) => (
                                    <li key={c.ulid}>
                                        <button
                                            type="button"
                                            className={`grid w-full gap-0.5 p-2 text-left ${current?.ulid === c.ulid ? 'bg-muted' : ''}`}
                                            onClick={() => go({ c: c.ulid })}
                                        >
                                            <span className="flex items-center gap-2">
                                                <span
                                                    className={
                                                        c.unread
                                                            ? 'font-semibold'
                                                            : ''
                                                    }
                                                >
                                                    {c.name}
                                                </span>
                                                {c.needs_reply && (
                                                    <Badge variant="destructive">
                                                        Needs reply
                                                    </Badge>
                                                )}
                                                <span className="ml-auto text-xs text-muted-foreground">
                                                    {c.at ? time(c.at) : ''}
                                                </span>
                                            </span>
                                            <span className="truncate text-muted-foreground">
                                                {c.preview}
                                            </span>
                                            {canManage && c.agent && (
                                                <span className="text-xs text-muted-foreground">
                                                    {c.agent}
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </aside>
                    <section className="min-w-0">
                        {current ? (
                            <Thread
                                key={current.ulid}
                                current={current}
                                opts={opts}
                            />
                        ) : (
                            <EmptyState
                                title="Choose a conversation"
                                description="Or open a contact and choose Message to start one."
                            />
                        )}
                    </section>
                </div>

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
}: {
    current: Current;
    opts: { preserveScroll: boolean };
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

    return (
        <div className="grid gap-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Link
                    className="text-base font-semibold underline"
                    href={`/contacts/${current.contact.ulid}`}
                >
                    {current.contact.name}
                </Link>
                <span className="text-muted-foreground">
                    {[current.contact.phone, current.contact.email]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            </div>
            {current.ai && (
                <div className="grid gap-1 rounded-lg border border-primary/40 p-3">
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
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3">
                    <p>
                        <span className="font-medium">Long follow-up: </span>
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
            <ol
                className="grid max-h-[28rem] gap-2 overflow-y-auto rounded-lg border p-3"
                aria-label="Messages"
            >
                {current.messages.length === 0 && (
                    <li className="text-muted-foreground">No messages yet.</li>
                )}
                {current.messages.map((m) => (
                    <li
                        key={m.ulid}
                        className={`max-w-[85%] rounded-lg p-2 ${m.direction === 'out' ? 'justify-self-end bg-primary text-primary-foreground' : 'justify-self-start bg-muted'}`}
                    >
                        {m.subject && (
                            <p className="font-medium">{m.subject}</p>
                        )}
                        <p className="whitespace-pre-wrap">{m.body}</p>
                        <p className="mt-1 text-[11px] opacity-75">
                            {m.channel === 'sms' ? 'Text' : 'Email'} ·{' '}
                            {time(m.at)}
                            {m.by ? ` · ${m.by}` : ''}
                            {m.ai
                                ? m.by
                                    ? ' · AI-drafted'
                                    : ' · AI assistant'
                                : ''}
                            {m.status === 'failed' ? ' · not delivered' : ''}
                        </p>
                    </li>
                ))}
            </ol>
            <form
                className="grid gap-2 rounded-lg border p-3"
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
                    <select
                        aria-label="Send as"
                        className="rounded border bg-background px-2 py-1.5"
                        value={form.data.channel}
                        onChange={(e) =>
                            form.setData(
                                'channel',
                                e.target.value as 'sms' | 'email',
                            )
                        }
                    >
                        <option value="sms">Text</option>
                        <option value="email">Email</option>
                    </select>
                    <select
                        aria-label="Template"
                        className="rounded border bg-background px-2 py-1.5"
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
                    aria-label="Message"
                    className="min-h-24 rounded border bg-background p-2"
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                />
                {form.data.channel === 'sms' && (
                    <span className="text-xs text-muted-foreground">
                        {form.data.body.length} characters
                    </span>
                )}
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
                <Button
                    className="justify-self-start"
                    disabled={form.processing || blocks.length > 0}
                >
                    Send
                </Button>
                {form.data.ai_drafted && (
                    <span className="text-xs text-muted-foreground">
                        AI suggested this reply. Read and edit it before you
                        send.
                    </span>
                )}
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
