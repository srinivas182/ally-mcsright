import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type PortalProps = {
    people: {
        ulid: string;
        role: string;
        name: string;
        email: string;
        locale: string;
        texts: boolean;
        last_seen: string | null;
        active: boolean;
        devices: number;
    }[];
    questions: {
        ulid: string;
        who: string;
        question: string;
        answer: string | null;
        status: string;
        reply: string | null;
        at: string;
    }[];
    feedback: {
        name: string;
        rating: number;
        comment: string | null;
        may_quote: boolean;
    }[];
    locales: Record<string, string>;
};

const select = 'rounded border bg-background px-2 py-1';

function Reply({ deal, question }: { deal: string; question: string }) {
    const form = useForm({ reply: '' });

    return (
        <form
            className="flex flex-wrap gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/deals/${deal}/portal/questions/${question}`, {
                    preserveScroll: true,
                });
            }}
        >
            <Input
                aria-label="Your reply"
                className="min-w-56 flex-1"
                placeholder="Your reply (emailed to them)"
                value={form.data.reply}
                onChange={(e) => form.setData('reply', e.target.value)}
            />
            <Button
                size="sm"
                type="submit"
                disabled={form.processing || form.data.reply.trim() === ''}
            >
                Reply
            </Button>
            {form.errors.reply && (
                <span className="w-full text-destructive">
                    {form.errors.reply}
                </span>
            )}
        </form>
    );
}

/**
 * The deal portal, agent side (specs/ai/02, DQ3): invite the clients and the other side, see who is in, answer the
 * questions the concierge passed on, and read the check-ins after closing.
 */
export function PortalPanel({
    dealUlid,
    portal,
    parties,
    roles,
}: {
    dealUlid: string;
    portal: PortalProps;
    parties: { role: string; name: string; email: string | null }[];
    roles: string[];
}) {
    const invite = useForm({
        role: 'buyer',
        name: '',
        email: '',
        locale: 'en',
    });
    const [open, setOpen] = useState(portal.people.length === 0);
    const waiting = portal.questions.filter((q) => q.status === 'to_agent');
    const invitable = parties.filter(
        (p) =>
            p.email &&
            !portal.people.some(
                (x) => x.active && x.email === p.email?.toLowerCase(),
            ),
    );

    return (
        <section
            className="grid gap-3 rounded-lg border p-4 text-sm"
            aria-label="Deal portal"
        >
            <div className="flex flex-wrap items-center gap-2">
                <p className="font-medium">Deal portal</p>
                {waiting.length > 0 && (
                    <Badge variant="destructive">
                        {waiting.length} question(s) for you
                    </Badge>
                )}
                <span className="text-muted-foreground">
                    Clients follow the deal, ask questions in English or
                    Spanish, and upload; the other side sees only their dates
                    and requests.
                </span>
            </div>
            {portal.people.length > 0 && (
                <ul className="grid gap-1">
                    {portal.people.map((p) => (
                        <li
                            key={p.ulid}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <span className={p.active ? '' : 'line-through'}>
                                {p.name} ({p.role.replace('_', '-')}), {p.email}
                            </span>
                            <Badge variant="outline">
                                {portal.locales[p.locale] ?? p.locale}
                            </Badge>
                            {p.texts && (
                                <Badge variant="secondary">Texts on</Badge>
                            )}
                            <span className="text-muted-foreground">
                                {p.last_seen
                                    ? `Last visit ${p.last_seen}`
                                    : 'Not visited yet'}
                            </span>
                            {p.active && p.devices > 0 && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    title="Ends every session; they sign in again with a new link and code"
                                    onClick={() =>
                                        router.delete(
                                            `/deals/${dealUlid}/portal/${p.ulid}/devices`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Sign out on {p.devices} device(s)
                                </Button>
                            )}
                            {p.active && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        router.delete(
                                            `/deals/${dealUlid}/portal/${p.ulid}`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {invitable.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {invitable.map((p) => (
                        <Button
                            key={p.email}
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/deals/${dealUlid}/portal`,
                                    {
                                        role: p.role,
                                        name: p.name,
                                        email: p.email,
                                        locale: 'en',
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Invite {p.name}
                        </Button>
                    ))}
                </div>
            )}
            <details
                open={open}
                onToggle={(e) => setOpen(e.currentTarget.open)}
            >
                <summary className="cursor-pointer">Invite someone</summary>
                <form
                    className="mt-2 grid gap-2 md:grid-cols-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        invite.post(`/deals/${dealUlid}/portal`, {
                            preserveScroll: true,
                            onSuccess: () => invite.reset('name', 'email'),
                        });
                    }}
                >
                    <select
                        aria-label="Role"
                        className={select}
                        value={invite.data.role}
                        onChange={(e) => invite.setData('role', e.target.value)}
                    >
                        {roles.map((r) => (
                            <option key={r} value={r}>
                                {r.replace('_', '-')}
                            </option>
                        ))}
                    </select>
                    <Input
                        aria-label="Name"
                        placeholder="Name"
                        value={invite.data.name}
                        onChange={(e) => invite.setData('name', e.target.value)}
                    />
                    <Input
                        aria-label="Email"
                        type="email"
                        placeholder="Email"
                        value={invite.data.email}
                        onChange={(e) =>
                            invite.setData('email', e.target.value)
                        }
                    />
                    <select
                        aria-label="Language"
                        className={select}
                        value={invite.data.locale}
                        onChange={(e) =>
                            invite.setData('locale', e.target.value)
                        }
                    >
                        {Object.entries(portal.locales).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                    <Button
                        type="submit"
                        size="sm"
                        className="justify-self-start"
                        disabled={invite.processing}
                    >
                        Send the invitation
                    </Button>
                    {invite.errors.email && (
                        <span className="text-destructive md:col-span-4">
                            {invite.errors.email}
                        </span>
                    )}
                </form>
            </details>
            {portal.questions.length > 0 && (
                <div className="grid gap-2">
                    <p className="font-medium">Questions</p>
                    {portal.questions.map((q) => (
                        <div
                            key={q.ulid}
                            className="grid gap-1 rounded-md border p-2"
                        >
                            <p>
                                <strong>{q.who}</strong> ({q.at}): “{q.question}
                                ”
                            </p>
                            {q.answer && (
                                <p className="text-muted-foreground">
                                    Concierge: {q.answer}
                                </p>
                            )}
                            {q.reply && <p>You replied: {q.reply}</p>}
                            {q.status === 'to_agent' && (
                                <Reply deal={dealUlid} question={q.ulid} />
                            )}
                        </div>
                    ))}
                </div>
            )}
            {portal.feedback.length > 0 && (
                <div className="grid gap-1">
                    <p className="font-medium">After closing</p>
                    {portal.feedback.map((f, i) => (
                        <p key={i}>
                            {f.name}: {f.rating}/5
                            {f.comment ? ` · “${f.comment}”` : ''}
                            {f.may_quote
                                ? ' · may be shared as a review'
                                : ' · private'}
                        </p>
                    ))}
                </div>
            )}
        </section>
    );
}
