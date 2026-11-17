import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';
import { postJson } from '@/lib/http';

type Suggestion = {
    id: number;
    name: string;
    office: string | null;
    sides: number;
    reason: string | null;
};

/** Refer an agent (RC3): agents suggest people they know; an AI draft helps them write their own personal note. */
export default function Refer({
    suggestions,
    mine,
    database,
    broker,
}: {
    suggestions: Suggestion[];
    mine: { name: string; stage: string; since: string }[];
    database: boolean;
    broker: boolean;
}) {
    const form = useForm({
        name: '',
        email: '',
        phone: '',
        brokerage: '',
        note: '',
    });
    const [draft, setDraft] = useState('');
    const [drafting, setDrafting] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    const write = async (name: string) => {
        setDrafting(true);
        setProblem(null);
        setCopied(false);
        try {
            const r = await postJson<{ message: string }>(
                '/recruiting/refer/draft',
                { name },
            );
            setDraft(r.message);
        } catch (e) {
            setProblem(e instanceof Error ? e.message : 'Please try again.');
        } finally {
            setDrafting(false);
        }
    };

    return (
        <>
            <Head title="Refer an agent" />
            <div className="grid max-w-4xl gap-5 p-4 text-base">
                <PageHeader
                    title="Refer an agent"
                    description="Know a good agent who'd like it here? Tell us, and we'll take it from there. If revenue share applies, agents you refer count towards yours."
                />

                <form
                    className="grid gap-3 rounded-lg border p-4 sm:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/recruiting/refer', {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <h2 className="font-semibold sm:col-span-2">
                        Someone you know
                    </h2>
                    <label className="grid gap-1 text-sm">
                        Their name
                        <Input
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Their brokerage
                        <Input
                            value={form.data.brokerage}
                            onChange={(e) =>
                                form.setData('brokerage', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Email
                        <Input
                            type="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Phone
                        <Input
                            inputMode="tel"
                            value={form.data.phone}
                            onChange={(e) =>
                                form.setData('phone', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm sm:col-span-2">
                        Anything that would help (optional)
                        <Textarea
                            rows={2}
                            value={form.data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </label>
                    <div className="flex flex-wrap gap-2 sm:col-span-2">
                        <Button disabled={form.processing || !form.data.name}>
                            Refer them
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={!form.data.name || drafting}
                            onClick={() => write(form.data.name)}
                        >
                            {drafting
                                ? 'Writing…'
                                : 'Help me write a note to them'}
                        </Button>
                    </div>
                    {Object.values(form.errors).map((m) => (
                        <p
                            key={m}
                            role="alert"
                            className="text-sm text-red-700 sm:col-span-2"
                        >
                            {m}
                        </p>
                    ))}
                </form>

                {(draft || problem) && (
                    <section
                        className="grid gap-2 rounded-lg border p-4"
                        aria-live="polite"
                    >
                        <h2 className="font-semibold">Your note</h2>
                        {problem ? (
                            <p className="text-sm text-red-700">{problem}</p>
                        ) : (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    A first draft. Make it yours, then send it
                                    from your own phone or email.
                                </p>
                                <Textarea
                                    rows={6}
                                    value={draft}
                                    onChange={(e) => setDraft(e.target.value)}
                                    aria-label="Your note"
                                />
                                <Button
                                    className="justify-self-start"
                                    variant="outline"
                                    onClick={() => {
                                        void navigator.clipboard?.writeText(
                                            draft,
                                        );
                                        setCopied(true);
                                    }}
                                >
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </>
                        )}
                    </section>
                )}

                {suggestions.length > 0 && (
                    <section
                        className="grid gap-2"
                        aria-labelledby="suggestions"
                    >
                        <h2 id="suggestions" className="font-semibold">
                            Agents you might know
                        </h2>
                        <ul className="grid gap-2">
                            {suggestions.map((s) => (
                                <li
                                    key={s.id}
                                    className="flex flex-wrap items-center gap-3 rounded-lg border p-3"
                                >
                                    <span className="min-w-0 flex-1">
                                        <strong>{s.name}</strong>
                                        <span className="block text-sm text-muted-foreground">
                                            {s.office ?? 'Office not known'} ·{' '}
                                            {s.sides} closings in 12 months
                                            {s.reason && ` · ${s.reason}`}
                                        </span>
                                    </span>
                                    <Button
                                        variant="outline"
                                        onClick={() => write(s.name)}
                                        disabled={drafting}
                                    >
                                        Write a note
                                    </Button>
                                    <Button
                                        onClick={() =>
                                            router.post(
                                                '/recruiting/refer',
                                                { mls_agent: s.id },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        I know them: refer
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {broker && (
                    <label className="flex items-center gap-2 rounded-lg border p-3 text-sm">
                        <input
                            type="checkbox"
                            className="size-5"
                            checked={database}
                            onChange={(e) =>
                                router.post(
                                    '/recruiting/refer/database',
                                    { on: e.target.checked },
                                    { preserveScroll: true },
                                )
                            }
                        />
                        Show agents producing agents from the MLS here, so they
                        can say who they know
                    </label>
                )}

                <section className="grid gap-2" aria-labelledby="mine">
                    <h2 id="mine" className="font-semibold">
                        People you've referred
                    </h2>
                    {mine.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nobody yet.
                        </p>
                    ) : (
                        <ul className="grid gap-2">
                            {mine.map((m, i) => (
                                <li
                                    key={i}
                                    className="flex flex-wrap items-center gap-2 rounded border p-3 text-sm"
                                >
                                    <strong>{m.name}</strong>
                                    <Badge variant="outline">{m.stage}</Badge>
                                    <span className="text-muted-foreground">
                                        since {m.since}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
