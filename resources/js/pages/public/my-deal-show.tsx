import { Head, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usd } from '@/lib/cents';
import { postJson } from '@/lib/http';

type Stage = { key: string; state: string; label: string; note: string };
type Question = {
    question: string;
    answer: string | null;
    status: string;
    reply: string | null;
    sources: string[];
};
type Props = {
    access: {
        ulid: string;
        name: string;
        role: string;
        locale: string;
        phone: string | null;
        texts: boolean;
        client: boolean;
    };
    deal: {
        street: string;
        city: string | null;
        state: string;
        closed: boolean;
    };
    stages: Stage[];
    next: string[];
    dates: {
        label: string;
        date: string;
        when: string;
        done: boolean;
        today: boolean;
    }[];
    requests: { ulid: string; what: string }[];
    documents: { ulid: string; name: string; shared: string }[];
    agent: { name: string; email: string | null; phone: string | null };
    questions: Question[];
    checkin: boolean;
    offers: {
        text: string;
        offers: {
            label: string;
            price_cents: number;
            net_cents: number;
            financing: string;
            closing_date: string | null;
            grade: string;
            reasons: string[];
            status: string;
        }[];
    } | null;
    repairs: {
        items: {
            title: string;
            location: string;
            ask: string;
            credit_cents: number;
            note: string;
        }[];
        credit_cents: number;
    }[];
    t: Record<string, string>;
    locales: Record<string, string>;
    uploaded: boolean;
    saved: boolean;
    thanks: boolean;
    mimes: string[];
};

/** Upload one file, for a request or (clients) to the agent. */
function UploadButton({
    base,
    label,
    request,
    mimes,
}: {
    base: string;
    label: string;
    request?: string;
    mimes: string[];
}) {
    const input = useRef<HTMLInputElement>(null);
    const form = useForm<{ file: File | null; request: string | undefined }>({
        file: null,
        request,
    });

    return (
        <>
            <input
                ref={input}
                type="file"
                className="hidden"
                accept={mimes.join(',')}
                aria-label={label}
                onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (f) {
                        form.transform(() => ({ file: f, request }));
                        form.post(`${base}/upload`, {
                            forceFormData: true,
                            preserveScroll: true,
                        });
                    }
                }}
            />
            <Button
                size="sm"
                variant="outline"
                disabled={form.processing}
                onClick={() => input.current?.click()}
            >
                {label}
            </Button>
            {form.errors.file && (
                <span className="text-destructive">{form.errors.file}</span>
            )}
        </>
    );
}

/** The deal portal: progress, what's next, dates, uploads, documents and the concierge (specs/ai/02, DQ3). */
export default function MyDealShow(p: Props) {
    const { t, access } = p;
    const base = `/my-deal/${access.ulid}`;
    const [question, setQuestion] = useState('');
    const [busy, setBusy] = useState(false);
    const [asked, setAsked] = useState<(Question & { ulid?: string })[]>([]);
    const prefs = useForm({
        locale: access.locale,
        phone: access.phone ?? '',
        texts: access.texts,
    });
    const feedback = useForm({ rating: 5, comment: '', may_quote: false });

    const ask = () => {
        setBusy(true);
        void postJson<{
            ulid: string;
            answer: string;
            status: string;
            sources: string[];
        }>(`${base}/ask`, { question })
            .then((r) => {
                setAsked([
                    {
                        ulid: r.ulid,
                        question,
                        answer: r.answer,
                        status: r.status,
                        reply: null,
                        sources: r.sources,
                    },
                    ...asked,
                ]);
                setQuestion('');
            })
            .finally(() => setBusy(false));
    };
    const all: (Question & { ulid?: string })[] = [...asked, ...p.questions];

    return (
        <>
            <Head title={`${t.title}: ${p.deal.street}`}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main
                lang={access.locale}
                className="mx-auto grid max-w-2xl gap-5 p-4 text-sm md:p-6"
            >
                <header className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p className="text-muted-foreground">{t.title}</p>
                        <h1 className="text-xl font-semibold">
                            {p.deal.street}
                        </h1>
                        <p className="text-muted-foreground">
                            {[p.deal.city, p.deal.state]
                                .filter(Boolean)
                                .join(', ')}
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => router.post('/my-deal/sign-out')}
                    >
                        {t.sign_out}
                    </Button>
                </header>
                <p
                    role="note"
                    className="rounded-md border border-amber-500 bg-amber-50 p-3 text-amber-950 dark:bg-amber-950 dark:text-amber-50"
                >
                    {t.fraud}
                </p>
                {(p.uploaded || p.saved || p.thanks) && (
                    <p role="status" className="rounded-md border p-3">
                        {p.uploaded
                            ? t.uploaded
                            : p.thanks
                              ? t.thanks
                              : t.saved}
                    </p>
                )}
                {p.stages.length > 0 && (
                    <section aria-labelledby="progress" className="grid gap-2">
                        <h2 id="progress" className="font-semibold">
                            {t.progress}
                        </h2>
                        <ol className="grid gap-2 sm:grid-cols-5">
                            {p.stages.map((s) => (
                                <li
                                    key={s.key}
                                    aria-current={
                                        s.state === 'current'
                                            ? 'step'
                                            : undefined
                                    }
                                    className={`rounded-md border p-2 ${s.state === 'current' ? 'border-primary bg-primary/10 font-medium' : s.state === 'done' ? 'text-muted-foreground' : ''}`}
                                >
                                    {s.state === 'done' ? '✓ ' : ''}
                                    {s.label}
                                </li>
                            ))}
                        </ol>
                        {p.stages
                            .filter((s) => s.state === 'current')
                            .map((s) => (
                                <p key={s.key}>{s.note}</p>
                            ))}
                    </section>
                )}
                <section aria-labelledby="next" className="grid gap-2">
                    <h2 id="next" className="font-semibold">
                        {t.next}
                    </h2>
                    {p.next.length === 0 ? (
                        <p className="text-muted-foreground">
                            {t.nothing_next}
                        </p>
                    ) : (
                        <ul className="list-disc pl-5">
                            {p.next.map((n) => (
                                <li key={n}>{n}</li>
                            ))}
                        </ul>
                    )}
                    {p.requests.map((r) => (
                        <span
                            key={r.ulid}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <span>{t.upload_for.replace(':what', r.what)}</span>
                            <UploadButton
                                base={base}
                                label={t.upload}
                                request={r.ulid}
                                mimes={p.mimes}
                            />
                        </span>
                    ))}
                    {access.client && (
                        <span>
                            <UploadButton
                                base={base}
                                label={t.upload_any}
                                mimes={p.mimes}
                            />
                        </span>
                    )}
                </section>
                {p.offers && (
                    <section aria-labelledby="offers" className="grid gap-2">
                        <h2 id="offers" className="font-semibold">
                            {t.offers}
                        </h2>
                        <p>{p.offers.text}</p>
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full min-w-[32rem] text-left">
                                <thead className="bg-muted/50">
                                    <tr>
                                        <th className="p-2" />
                                        <th className="p-2">
                                            {t.offers_price}
                                        </th>
                                        <th className="p-2">{t.offers_net}</th>
                                        <th className="p-2">
                                            {t.offers_financing}
                                        </th>
                                        <th className="p-2">
                                            {t.offers_closing}
                                        </th>
                                        <th className="p-2">
                                            {t.offers_likely}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y align-top">
                                    {p.offers.offers.map((o) => (
                                        <tr key={o.label}>
                                            <td className="p-2 font-medium">
                                                {o.label}
                                            </td>
                                            <td className="p-2">
                                                {usd(o.price_cents)}
                                            </td>
                                            <td className="p-2 font-medium">
                                                {usd(o.net_cents)}
                                            </td>
                                            <td className="p-2">
                                                {o.financing}
                                            </td>
                                            <td className="p-2">
                                                {o.closing_date ?? '—'}
                                            </td>
                                            <td className="p-2">
                                                {o.grade}
                                                {o.reasons.length > 0 && (
                                                    <ul className="text-xs text-muted-foreground">
                                                        {o.reasons.map((r) => (
                                                            <li key={r}>{r}</li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {t.offers_note}
                        </p>
                    </section>
                )}
                {p.repairs.map((r, i) => (
                    <section
                        key={i}
                        aria-labelledby="repairs"
                        className="grid gap-2"
                    >
                        <h2 id="repairs" className="font-semibold">
                            {t.repairs}
                        </h2>
                        <p className="text-muted-foreground">
                            {t.repairs_note}
                        </p>
                        <ul className="divide-y rounded-md border">
                            {r.items.map((it) => (
                                <li
                                    key={it.title + it.location}
                                    className="flex justify-between gap-2 p-2"
                                >
                                    <span>
                                        {it.title}
                                        {it.location ? ` · ${it.location}` : ''}
                                        {it.note && (
                                            <span className="block text-xs text-muted-foreground">
                                                {it.note}
                                            </span>
                                        )}
                                    </span>
                                    <span>
                                        {it.ask === 'credit'
                                            ? `${t.repairs_credit}: ${usd(it.credit_cents)}`
                                            : t.repairs_repair}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
                <section aria-labelledby="dates" className="grid gap-2">
                    <h2 id="dates" className="font-semibold">
                        {t.dates}
                    </h2>
                    {p.dates.length === 0 ? (
                        <p className="text-muted-foreground">{t.no_dates}</p>
                    ) : (
                        <ul className="divide-y rounded-md border">
                            {p.dates.map((d) => (
                                <li
                                    key={d.label + d.date}
                                    className="flex justify-between gap-2 p-2"
                                >
                                    <span
                                        className={
                                            d.done
                                                ? 'text-muted-foreground line-through'
                                                : ''
                                        }
                                    >
                                        {d.label}
                                    </span>
                                    <time dateTime={d.date}>
                                        {d.today ? t.today : d.when}
                                        {d.done ? ` · ${t.done}` : ''}
                                    </time>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                {access.client && (
                    <section aria-labelledby="documents" className="grid gap-2">
                        <h2 id="documents" className="font-semibold">
                            {t.documents}
                        </h2>
                        {p.documents.length === 0 ? (
                            <p className="text-muted-foreground">
                                {t.no_documents}
                            </p>
                        ) : (
                            <ul className="grid gap-1">
                                {p.documents.map((d) => (
                                    <li key={d.ulid}>
                                        <a
                                            className="text-primary underline"
                                            href={`${base}/documents/${d.ulid}`}
                                        >
                                            {d.name}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
                {access.client && (
                    <section aria-labelledby="ask" className="grid gap-2">
                        <h2 id="ask" className="font-semibold">
                            {t.ask}
                        </h2>
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                ask();
                            }}
                        >
                            <Input
                                aria-label={t.ask}
                                className="min-w-56 flex-1"
                                placeholder={t.ask_hint}
                                value={question}
                                maxLength={1000}
                                onChange={(e) => setQuestion(e.target.value)}
                            />
                            <Button
                                type="submit"
                                disabled={busy || question.trim() === ''}
                            >
                                {t.ask_send}
                            </Button>
                        </form>
                        {all.map((q, i) => (
                            <div
                                key={i}
                                className="grid gap-1 rounded-md border p-2"
                            >
                                <p className="font-medium">{q.question}</p>
                                {q.answer && <p>{q.answer}</p>}
                                {q.sources.length > 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        {t.from.replace(
                                            ':sources',
                                            q.sources.join(', '),
                                        )}
                                    </p>
                                )}
                                {q.reply && (
                                    <p>
                                        <strong>{t.agent_replied}:</strong>{' '}
                                        {q.reply}
                                    </p>
                                )}
                                {q.status === 'answered' &&
                                    q.ulid !== undefined && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="justify-self-start"
                                            onClick={() => {
                                                void postJson(
                                                    `${base}/ask/${q.ulid}/agent`,
                                                    {},
                                                );
                                                setAsked(
                                                    asked.map((x) =>
                                                        x.ulid === q.ulid
                                                            ? {
                                                                  ...x,
                                                                  status: 'to_agent',
                                                              }
                                                            : x,
                                                    ),
                                                );
                                            }}
                                        >
                                            {t.ask_agent}
                                        </Button>
                                    )}
                                {q.status === 'to_agent' && (
                                    <p className="text-xs text-muted-foreground">
                                        {t.sent_to_agent}
                                    </p>
                                )}
                            </div>
                        ))}
                    </section>
                )}
                {p.checkin && (
                    <section
                        aria-labelledby="checkin"
                        className="grid gap-2 rounded-md border p-3"
                    >
                        <h2 id="checkin" className="font-semibold">
                            {t.checkin_title}
                        </h2>
                        <p>{t.checkin_intro}</p>
                        <form
                            className="grid gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                feedback.post(`${base}/feedback`, {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <fieldset className="flex gap-2">
                                <legend className="mb-1">{t.rating}</legend>
                                {[1, 2, 3, 4, 5].map((n) => (
                                    <label
                                        key={n}
                                        className="flex items-center gap-1"
                                    >
                                        <input
                                            type="radio"
                                            name="rating"
                                            checked={feedback.data.rating === n}
                                            onChange={() =>
                                                feedback.setData('rating', n)
                                            }
                                        />
                                        {n}
                                    </label>
                                ))}
                            </fieldset>
                            <Input
                                aria-label={t.comment}
                                placeholder={t.comment}
                                value={feedback.data.comment}
                                onChange={(e) =>
                                    feedback.setData('comment', e.target.value)
                                }
                            />
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={feedback.data.may_quote}
                                    onChange={(e) =>
                                        feedback.setData(
                                            'may_quote',
                                            e.target.checked,
                                        )
                                    }
                                />
                                {t.may_quote}
                            </label>
                            <Button
                                type="submit"
                                size="sm"
                                className="justify-self-start"
                                disabled={feedback.processing}
                            >
                                {t.save}
                            </Button>
                        </form>
                    </section>
                )}
                <section aria-labelledby="agent" className="grid gap-1">
                    <h2 id="agent" className="font-semibold">
                        {t.your_agent}
                    </h2>
                    <p>{p.agent.name}</p>
                    {p.agent.phone && (
                        <a className="underline" href={`tel:${p.agent.phone}`}>
                            {p.agent.phone}
                        </a>
                    )}
                    {p.agent.email && (
                        <a
                            className="underline"
                            href={`mailto:${p.agent.email}`}
                        >
                            {p.agent.email}
                        </a>
                    )}
                </section>
                <details className="rounded-md border p-3">
                    <summary className="cursor-pointer font-medium">
                        {t.settings}
                    </summary>
                    <form
                        className="mt-2 grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            prefs.post(`${base}/preferences`, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <label className="grid gap-1">
                            <span>{t.language}</span>
                            <select
                                className="rounded border bg-background px-2 py-1"
                                value={prefs.data.locale}
                                onChange={(e) =>
                                    prefs.setData('locale', e.target.value)
                                }
                            >
                                {Object.entries(p.locales).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {access.client && (
                            <>
                                <label className="grid gap-1">
                                    <span>{t.phone}</span>
                                    <Input
                                        type="tel"
                                        autoComplete="tel"
                                        value={prefs.data.phone}
                                        onChange={(e) =>
                                            prefs.setData(
                                                'phone',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {prefs.errors.phone && (
                                        <span className="text-destructive">
                                            {prefs.errors.phone}
                                        </span>
                                    )}
                                </label>
                                <label className="flex items-start gap-2">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5"
                                        checked={prefs.data.texts}
                                        onChange={(e) =>
                                            prefs.setData(
                                                'texts',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    {t.sms_consent}
                                </label>
                            </>
                        )}
                        <Button
                            type="submit"
                            size="sm"
                            className="justify-self-start"
                            disabled={prefs.processing}
                        >
                            {t.save}
                        </Button>
                    </form>
                </details>
            </main>
        </>
    );
}
