import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import {
    AbTest,
    type AbTestResults,
    type AbTestSettings,
} from '@/components/ailly/ab-test';
import { LiveChats, type LiveChat } from '@/components/ailly/live-chats';
import { MediaPicker, type MediaItem } from '@/components/sites/media-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';

type Settings = {
    enabled: boolean;
    name: string;
    photo: string | null;
    greeting: string;
    gate: 'before' | 'after' | 'booking';
    gate_after: number;
    visitor_daily: number;
    daily_cap: number;
    team: number[];
    nudge: boolean;
};
type Conversation = {
    ulid: string;
    at: string;
    page: string;
    outcome: 'none' | 'lead' | 'call' | 'showing' | 'listing';
    score: number | null;
    answers: number;
    messages: { role: string; body: string; flagged: boolean }[];
};
type Results = {
    chats: number;
    leads: number;
    calls: number;
    showings: number;
    listings: number;
    lead_rate: number;
    answers: number;
    free_answers: number;
    credits: number;
    spent_cents: number;
    cost_per_lead_cents: number | null;
    cost_per_call_cents: number | null;
    flagged: number;
};
type Props = {
    site: { live: boolean; name: string } | null;
    site_ulid: string | null;
    photo_url: string | null;
    media: MediaItem[];
    settings: Settings;
    filters: { days: number; outcome: string; score: number | null };
    results: Results;
    by_page: { page: string; chats: number; leads: number; rate: number }[];
    by_site: {
        site: string;
        chats: number;
        leads: number;
        calls: number;
        rate: number;
    }[];
    conversations: Conversation[];
    answers: { id: number; question: string; answer: string; hits: number }[];
    open_questions: {
        id: number;
        question: string;
        times: number;
        last_asked_at: string;
    }[];
    team_options: { id: number; name: string }[];
    test: AbTestSettings;
    test_results: AbTestResults;
    current_greeting: string;
    live: LiveChat[];
};

const outcomeLabel: Record<Conversation['outcome'], string> = {
    none: 'No details yet',
    lead: 'Lead',
    call: 'Call booked',
    showing: 'Showing booked',
    listing: 'Listing appointment',
};

const usd = (cents: number | null) =>
    cents === null
        ? '—'
        : new Intl.NumberFormat('en-US', {
              style: 'currency',
              currency: 'USD',
          }).format(cents / 100);

function filter(next: Partial<Props['filters']>, current: Props['filters']) {
    router.get(
        '/settings/ailly',
        {
            ...current,
            ...next,
            score: next.score ?? current.score ?? undefined,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function AnswerForm({
    initial,
    onDone,
}: {
    initial?: { id?: number; question: string; answer: string };
    onDone?: () => void;
}) {
    const form = useForm({
        id: initial?.id ?? null,
        question: initial?.question ?? '',
        answer: initial?.answer ?? '',
    });
    return (
        <form
            className="grid gap-2 rounded-md border p-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/settings/ailly/answers', {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        onDone?.();
                    },
                });
            }}
        >
            <Input
                aria-label="Question"
                placeholder="Question, e.g. What are your office hours?"
                value={form.data.question}
                onChange={(e) => form.setData('question', e.target.value)}
                maxLength={200}
            />
            <Textarea
                aria-label="Answer"
                rows={3}
                placeholder="Your answer, as you want AIlly to give it"
                value={form.data.answer}
                onChange={(e) => form.setData('answer', e.target.value)}
                maxLength={1200}
            />
            {form.errors.question && (
                <p className="text-destructive">{form.errors.question}</p>
            )}
            <div>
                <Button size="sm" disabled={form.processing}>
                    {initial?.id ? 'Save answer' : 'Add answer'}
                </Button>
            </div>
        </form>
    );
}

/** Settings → AIlly assistant: results, chats, the brokerage's answers, and settings. */
export default function AillySettings({
    site,
    site_ulid,
    settings,
    filters,
    results,
    by_page,
    by_site,
    conversations,
    answers,
    open_questions,
    team_options,
    test,
    test_results,
    current_greeting,
    live,
    media: initialMedia,
}: Props) {
    const form = useForm({ ...settings });
    const [media, setMedia] = useState(initialMedia);
    const [open, setOpen] = useState<string | null>(null);
    const [prefill, setPrefill] = useState<string | null>(null);
    const [editing, setEditing] = useState<number | null>(null);
    const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
    const tiles: [string, string][] = [
        ['Chats', results.chats.toLocaleString()],
        ['Leads', `${results.leads.toLocaleString()} (${results.lead_rate}%)`],
        ['Calls booked', results.calls.toLocaleString()],
        ['Showings booked', results.showings.toLocaleString()],
        ['Listing appointments', results.listings.toLocaleString()],
        ['Spent in credits', usd(results.spent_cents)],
        ['Cost per lead', usd(results.cost_per_lead_cents)],
        ['Cost per booked call', usd(results.cost_per_call_cents)],
        ['Free answers', results.free_answers.toLocaleString()],
    ];

    return (
        <>
            <Head title="AIlly assistant" />
            <div className="flex max-w-5xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="AIlly assistant"
                    description="The chat on your website that answers questions, shows matching homes and turns visitors into leads for you and your agents."
                />
                {site === null && (
                    <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                        Set up your website first (Settings → Website); AIlly
                        appears on it once switched on.
                    </p>
                )}
                <Tabs defaultValue="results">
                    <TabsList>
                        <TabsTrigger value="results">Results</TabsTrigger>
                        <TabsTrigger value="chats">Chats</TabsTrigger>
                        <TabsTrigger value="answers">
                            Answers
                            {open_questions.length > 0
                                ? ` (${open_questions.length} to add)`
                                : ''}
                        </TabsTrigger>
                        <TabsTrigger value="test">
                            A/B test{test.on ? ' (running)' : ''}
                        </TabsTrigger>
                        <TabsTrigger value="settings">Settings</TabsTrigger>
                    </TabsList>

                    <TabsContent value="test">
                        <AbTest
                            test={test}
                            results={test_results}
                            current={{
                                greeting: current_greeting,
                                gate: settings.gate,
                            }}
                        />
                    </TabsContent>

                    <TabsContent value="results" className="grid gap-4">
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <span>Last</span>
                            {[7, 30, 90].map((d) => (
                                <Button
                                    key={d}
                                    size="sm"
                                    variant={
                                        filters.days === d
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => filter({ days: d }, filters)}
                                >
                                    {d} days
                                </Button>
                            ))}
                        </div>
                        <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {tiles.map(([label, value]) => (
                                <div
                                    key={label}
                                    className="rounded-lg border p-4 text-sm"
                                >
                                    <p className="text-muted-foreground">
                                        {label}
                                    </p>
                                    <p className="text-2xl font-semibold">
                                        {value}
                                    </p>
                                </div>
                            ))}
                        </section>
                        <p className="text-sm text-muted-foreground">
                            {results.answers.toLocaleString()} AI answers and
                            closing briefs used{' '}
                            {results.credits.toLocaleString()} RightAlly
                            credits. Repeated questions and your own answers are
                            free.
                        </p>
                        <section className="grid gap-3 md:grid-cols-2">
                            <div className="rounded-lg border p-4 text-sm">
                                <h2 className="mb-2 font-semibold">
                                    Which pages turn chats into leads
                                </h2>
                                {by_page.length === 0 && (
                                    <p className="text-muted-foreground">
                                        No chats yet.
                                    </p>
                                )}
                                <ul className="grid gap-1">
                                    {by_page.map((p) => (
                                        <li
                                            key={p.page}
                                            className="flex justify-between gap-2"
                                        >
                                            <span className="truncate">
                                                {p.page}
                                            </span>
                                            <span className="shrink-0 text-muted-foreground">
                                                {p.chats} chats · {p.leads}{' '}
                                                leads · {p.rate}%
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            <div className="rounded-lg border p-4 text-sm">
                                <h2 className="mb-2 font-semibold">
                                    By website
                                </h2>
                                {by_site.length === 0 && (
                                    <p className="text-muted-foreground">
                                        No chats yet.
                                    </p>
                                )}
                                <ul className="grid gap-1">
                                    {by_site.map((s) => (
                                        <li
                                            key={s.site}
                                            className="flex justify-between gap-2"
                                        >
                                            <span>{s.site}</span>
                                            <span className="text-muted-foreground">
                                                {s.chats} chats · {s.leads}{' '}
                                                leads · {s.calls} calls ·{' '}
                                                {s.rate}%
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </section>
                        <section className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-4 text-sm">
                            <span>
                                <span className="font-medium">
                                    Fair Housing audit:
                                </span>{' '}
                                {results.flagged.toLocaleString()} answers in
                                this period had a sentence removed by the safety
                                check. Export every answer and what was removed.
                            </span>
                            <span className="flex items-center gap-2">
                                <Input
                                    aria-label="Month to export"
                                    type="month"
                                    className="h-8 w-40"
                                    value={month}
                                    onChange={(e) => setMonth(e.target.value)}
                                />
                                <Button size="sm" variant="outline" asChild>
                                    <a
                                        href={`/settings/ailly/audit?month=${encodeURIComponent(month)}`}
                                    >
                                        Export (CSV)
                                    </a>
                                </Button>
                            </span>
                        </section>
                    </TabsContent>

                    <TabsContent value="chats" className="grid gap-3 text-sm">
                        <section className="grid gap-2">
                            <h2 className="font-medium">Live now</h2>
                            <LiveChats live={live} />
                        </section>
                        <div className="flex flex-wrap items-center gap-2">
                            <select
                                aria-label="Outcome"
                                className="h-9 rounded-md border bg-background px-2"
                                value={filters.outcome}
                                onChange={(e) =>
                                    filter({ outcome: e.target.value }, filters)
                                }
                            >
                                <option value="all">All chats</option>
                                <option value="call">Calls booked</option>
                                <option value="showing">Showings booked</option>
                                <option value="listing">
                                    Listing appointments
                                </option>
                                <option value="lead">Leads</option>
                                <option value="none">No details yet</option>
                            </select>
                            <select
                                aria-label="Lead score"
                                className="h-9 rounded-md border bg-background px-2"
                                value={filters.score ?? ''}
                                onChange={(e) =>
                                    filter(
                                        {
                                            score:
                                                e.target.value === ''
                                                    ? null
                                                    : parseInt(
                                                          e.target.value,
                                                          10,
                                                      ),
                                        },
                                        filters,
                                    )
                                }
                            >
                                <option value="">Any lead score</option>
                                <option value="70">Hot (70+)</option>
                                <option value="40">Warm (40+)</option>
                            </select>
                        </div>
                        {conversations.length === 0 && (
                            <p className="text-muted-foreground">
                                No chats match.
                            </p>
                        )}
                        <ul className="divide-y rounded-lg border">
                            {conversations.map((c) => (
                                <li key={c.ulid} className="grid gap-2 p-3">
                                    <button
                                        type="button"
                                        className="flex flex-wrap items-center justify-between gap-2 text-left"
                                        aria-expanded={open === c.ulid}
                                        onClick={() =>
                                            setOpen(
                                                open === c.ulid ? null : c.ulid,
                                            )
                                        }
                                    >
                                        <span>
                                            {new Date(c.at).toLocaleString()} ·{' '}
                                            {c.page || '/'} · {c.answers}{' '}
                                            answers
                                        </span>
                                        <span className="flex items-center gap-2">
                                            {c.score !== null && (
                                                <span className="text-muted-foreground">
                                                    Lead score {c.score}
                                                </span>
                                            )}
                                            <Badge
                                                variant={
                                                    c.outcome === 'none'
                                                        ? 'outline'
                                                        : 'default'
                                                }
                                            >
                                                {outcomeLabel[c.outcome]}
                                            </Badge>
                                        </span>
                                    </button>
                                    {open === c.ulid && (
                                        <div className="grid gap-1 rounded-md bg-muted/40 p-3">
                                            <a
                                                href={`/ailly/chats/${c.ulid}`}
                                                className="justify-self-end text-xs underline-offset-4 hover:underline"
                                            >
                                                Open the chat
                                            </a>
                                            {c.messages.map((m, i) => (
                                                <p key={i}>
                                                    <strong>
                                                        {m.role === 'visitor'
                                                            ? 'Visitor'
                                                            : m.role === 'agent'
                                                              ? 'Agent'
                                                              : m.role ===
                                                                  'note'
                                                                ? 'Note'
                                                                : 'AIlly'}
                                                        :
                                                    </strong>{' '}
                                                    {m.body}
                                                    {m.flagged && (
                                                        <Badge
                                                            variant="outline"
                                                            className="ml-2"
                                                        >
                                                            Safety check removed
                                                            a sentence
                                                        </Badge>
                                                    )}
                                                </p>
                                            ))}
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </TabsContent>

                    <TabsContent value="answers" className="grid gap-4 text-sm">
                        <section className="grid gap-2">
                            <h2 className="font-semibold">
                                Questions AIlly couldn't answer
                            </h2>
                            <p className="text-muted-foreground">
                                Add an answer once and AIlly gives it word for
                                word from then on, for free.
                            </p>
                            {open_questions.length === 0 && (
                                <p className="text-muted-foreground">
                                    None right now.
                                </p>
                            )}
                            <ul className="divide-y rounded-lg border">
                                {open_questions.map((q) => (
                                    <li key={q.id} className="grid gap-2 p-3">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span>
                                                “{q.question}”{' '}
                                                <span className="text-muted-foreground">
                                                    · asked {q.times}×
                                                </span>
                                            </span>
                                            <span className="flex gap-2">
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        setPrefill(
                                                            prefill ===
                                                                q.question
                                                                ? null
                                                                : q.question,
                                                        )
                                                    }
                                                >
                                                    Add an answer
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        router.post(
                                                            `/settings/ailly/questions/${q.id}/dismiss`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Dismiss
                                                </Button>
                                            </span>
                                        </div>
                                        {prefill === q.question && (
                                            <AnswerForm
                                                initial={{
                                                    question: q.question,
                                                    answer: '',
                                                }}
                                                onDone={() => setPrefill(null)}
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                        <section className="grid gap-2">
                            <h2 className="font-semibold">
                                Your answers ({answers.length} of 50)
                            </h2>
                            <AnswerForm />
                            <ul className="divide-y rounded-lg border">
                                {answers.map((a) => (
                                    <li key={a.id} className="grid gap-1 p-3">
                                        {editing === a.id ? (
                                            <AnswerForm
                                                initial={a}
                                                onDone={() => setEditing(null)}
                                            />
                                        ) : (
                                            <>
                                                <p className="font-medium">
                                                    {a.question}
                                                </p>
                                                <p className="whitespace-pre-line">
                                                    {a.answer}
                                                </p>
                                                <span className="flex items-center gap-2 text-muted-foreground">
                                                    Used {a.hits}×
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setEditing(a.id)
                                                        }
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            router.delete(
                                                                `/settings/ailly/answers/${a.id}`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                </span>
                                            </>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </TabsContent>

                    <TabsContent value="settings">
                        <form
                            className="grid gap-4 rounded-lg border p-4 text-sm"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/settings/ailly', {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <label className="flex items-center gap-3">
                                <Switch
                                    checked={form.data.enabled}
                                    onCheckedChange={(v) =>
                                        form.setData('enabled', v)
                                    }
                                />
                                <span className="font-medium">
                                    Show AIlly on our website
                                </span>
                                {settings.enabled ? (
                                    <Badge>On</Badge>
                                ) : (
                                    <Badge variant="outline">Off</Badge>
                                )}
                            </label>
                            <div className="grid gap-3 md:grid-cols-2">
                                <label className="grid gap-1">
                                    Assistant's name
                                    <Input
                                        value={form.data.name}
                                        maxLength={30}
                                        onChange={(e) =>
                                            form.setData('name', e.target.value)
                                        }
                                    />
                                </label>
                                {site_ulid && (
                                    <div className="grid gap-1">
                                        <MediaPicker
                                            label="Profile photo (optional; a friendly illustration is used otherwise)"
                                            value={form.data.photo}
                                            media={media}
                                            site={site_ulid}
                                            onChange={(v) =>
                                                form.setData('photo', v)
                                            }
                                            onUploaded={(m) =>
                                                setMedia([m, ...media])
                                            }
                                        />
                                    </div>
                                )}
                                <label className="grid gap-1 md:col-span-2">
                                    Greeting (optional)
                                    <Textarea
                                        rows={2}
                                        maxLength={240}
                                        placeholder={`Hi, I'm ${form.data.name}. I can help you find homes, answer questions about buying or selling, or connect you with an agent.`}
                                        value={form.data.greeting}
                                        onChange={(e) =>
                                            form.setData(
                                                'greeting',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                            </div>
                            <fieldset className="grid gap-2">
                                <legend className="mb-1 font-medium">
                                    When should AIlly ask for name, email and
                                    phone?
                                </legend>
                                {(
                                    [
                                        [
                                            'before',
                                            'Before the first answer',
                                            'Most leads per chat, but more visitors leave without asking.',
                                        ],
                                        [
                                            'after',
                                            'After a few answers (recommended)',
                                            'Visitors see value first, then share details to keep going.',
                                        ],
                                        [
                                            'booking',
                                            'Only when booking a call or a showing',
                                            'Friendliest; fewer leads, but very warm ones.',
                                        ],
                                    ] as const
                                ).map(([value, label, hint]) => (
                                    <label
                                        key={value}
                                        className="flex items-start gap-2"
                                    >
                                        <input
                                            type="radio"
                                            name="gate"
                                            className="mt-1"
                                            checked={form.data.gate === value}
                                            onChange={() =>
                                                form.setData('gate', value)
                                            }
                                        />
                                        <span>
                                            {label}
                                            {value === 'after' && (
                                                <>
                                                    {' '}
                                                    (after{' '}
                                                    <Input
                                                        type="number"
                                                        min={1}
                                                        max={10}
                                                        className="inline-block h-7 w-16"
                                                        value={
                                                            form.data.gate_after
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'gate_after',
                                                                parseInt(
                                                                    e.target
                                                                        .value ||
                                                                        '1',
                                                                    10,
                                                                ),
                                                            )
                                                        }
                                                    />{' '}
                                                    answers)
                                                </>
                                            )}
                                            <span className="block text-muted-foreground">
                                                {hint}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </fieldset>
                            <div className="grid gap-3 md:grid-cols-2">
                                <label className="grid gap-1">
                                    Most messages one visitor can send a day
                                    <Input
                                        type="number"
                                        min={5}
                                        max={100}
                                        value={form.data.visitor_daily}
                                        onChange={(e) =>
                                            form.setData(
                                                'visitor_daily',
                                                parseInt(
                                                    e.target.value || '5',
                                                    10,
                                                ),
                                            )
                                        }
                                    />
                                </label>
                                <label className="grid gap-1">
                                    Most new chats a day
                                    <Input
                                        type="number"
                                        min={10}
                                        max={10000}
                                        value={form.data.daily_cap}
                                        onChange={(e) =>
                                            form.setData(
                                                'daily_cap',
                                                parseInt(
                                                    e.target.value || '10',
                                                    10,
                                                ),
                                            )
                                        }
                                    />
                                </label>
                            </div>
                            <fieldset className="grid gap-2">
                                <legend className="font-medium">
                                    Who takes bookings from the brokerage
                                    website
                                </legend>
                                <span className="text-muted-foreground">
                                    Visitors pick an open time from these
                                    agents' hours (each sets them in My
                                    availability). Leave all unticked to use
                                    every agent who has hours set. Agent
                                    websites always book with that agent.
                                </span>
                                <div className="grid gap-1 sm:grid-cols-2 md:grid-cols-3">
                                    {team_options.map((a) => (
                                        <label
                                            key={a.id}
                                            className="flex items-center gap-2"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={form.data.team.includes(
                                                    a.id,
                                                )}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'team',
                                                        e.target.checked
                                                            ? [
                                                                  ...form.data
                                                                      .team,
                                                                  a.id,
                                                              ]
                                                            : form.data.team.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      a.id,
                                                              ),
                                                    )
                                                }
                                            />
                                            {a.name}
                                        </label>
                                    ))}
                                    {team_options.length === 0 && (
                                        <span className="text-muted-foreground">
                                            No active agents yet.
                                        </span>
                                    )}
                                </div>
                            </fieldset>
                            <label className="flex items-center gap-3">
                                <Switch
                                    checked={form.data.nudge}
                                    onCheckedChange={(v) =>
                                        form.setData('nudge', v)
                                    }
                                />
                                <span>
                                    Offer help on home pages after 40 seconds
                                    <span className="block text-muted-foreground">
                                        A small bubble, once per visit. The chat
                                        only starts if the visitor clicks it.
                                    </span>
                                </span>
                            </label>
                            <p className="text-muted-foreground">
                                To keep credits for real people, AIlly ignores
                                bots, answers a repeated first question for
                                free, ends chats that aren't about homes, and
                                asks for details instead of answering when
                                credits run out. Every chat that shares details
                                becomes a lead (on an agent's website, that
                                agent's lead).
                            </p>
                            {Object.values(form.errors).map((e) => (
                                <p key={e} className="text-destructive">
                                    {e}
                                </p>
                            ))}
                            <div>
                                <Button disabled={form.processing}>Save</Button>
                            </div>
                        </form>
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

AillySettings.layout = {
    breadcrumbs: [{ title: 'AIlly assistant', href: '/settings/ailly' }],
};
