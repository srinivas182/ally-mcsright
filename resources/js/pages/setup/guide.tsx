import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Progress } from '@/components/ui/progress';
import { Textarea } from '@/components/ui/textarea';

type Answers = {
    pay_mode?: 'split' | 'flat';
    agent_percent?: number;
    cap_dollars?: number;
    fee_dollars?: number;
    flat_fee_dollars?: number;
    lead_routing?: 'round_robin' | 'first_claim' | 'owner';
    ai_first_reply?: boolean;
    website_style?: 'light' | 'dark' | 'warm';
};

type Props = {
    answers: Answers;
    suggested: boolean;
    unclear: string[];
    applied: Record<string, string>;
    readiness: {
        score: number;
        steps: { key: string; label: string; done: boolean; href: string }[];
    };
    routing: Record<string, string>;
};

function Choice<T extends string | boolean>({
    value,
    current,
    onPick,
    children,
}: {
    value: T;
    current: T | undefined;
    onPick: (v: T) => void;
    children: React.ReactNode;
}) {
    return (
        <Button
            type="button"
            size="lg"
            variant={current === value ? 'default' : 'outline'}
            aria-pressed={current === value}
            onClick={() => onPick(value)}
        >
            {children}
        </Button>
    );
}

/** SU2: a few plain questions (or the broker's own words) and RightAlly sets itself up. */
export default function SetupGuide({
    answers,
    suggested,
    unclear,
    applied,
    readiness,
    routing,
}: Props) {
    const words = useForm({ words: '' });
    const form = useForm<Answers>({ ...answers });
    const set = <K extends keyof Answers>(k: K, v: Answers[K]) =>
        form.setData((d: Answers) => ({ ...d, [k]: v }));

    // The AI's reading comes back as new props on the same page: show it in the questions below.
    useEffect(() => {
        if (suggested) {
            form.setData((d: Answers) => ({ ...d, ...answers }));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [suggested, answers]);

    return (
        <>
            <Head title="Guided setup" />
            <div className="flex flex-col gap-8 p-4 md:p-6">
                <PageHeader
                    title="Guided setup"
                    description="Answer a few questions, or just tell us about your brokerage in your own words, and RightAlly sets itself up. You can change any answer later."
                />

                <section
                    aria-labelledby="ready"
                    className="grid gap-3 rounded-lg border p-4"
                >
                    <h2 id="ready" className="text-xl font-semibold">
                        Ready to go live: {readiness.score}%
                    </h2>
                    <Progress
                        value={readiness.score}
                        aria-label={`${readiness.score}% ready`}
                    />
                    <ul className="grid gap-2 text-base">
                        {readiness.steps.map((s) => (
                            <li
                                key={s.key}
                                className="flex flex-wrap items-center gap-2"
                            >
                                <span aria-hidden>{s.done ? '✓' : '○'}</span>
                                <span>{s.label}</span>
                                {!s.done && (
                                    <Link
                                        className="text-sm underline"
                                        href={s.href}
                                    >
                                        Do this
                                    </Link>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                <section aria-labelledby="words" className="grid gap-3">
                    <h2 id="words" className="text-xl font-semibold">
                        Tell us in your own words
                    </h2>
                    <form
                        className="grid gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            words.post('/setup/guide/describe', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <label className="grid gap-1 text-base">
                            For example: "Agents get 80/20 with an $18,000 cap
                            and a $395 fee per deal. New leads go round robin.
                            We'd like the assistant to answer new leads. Light,
                            clean website."
                            <Textarea
                                rows={4}
                                value={words.data.words}
                                onChange={(e) =>
                                    words.setData('words', e.target.value)
                                }
                                maxLength={4000}
                            />
                        </label>
                        {words.errors.words && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {words.errors.words}
                            </p>
                        )}
                        <div>
                            <Button
                                type="submit"
                                size="lg"
                                variant="outline"
                                disabled={words.processing}
                            >
                                Fill in the answers for me
                            </Button>
                        </div>
                    </form>
                    {suggested && (
                        <p
                            role="status"
                            className="rounded-md border p-3 text-base"
                        >
                            We filled in what you told us below. Please check
                            it, then press "Set it up".
                            {unclear.length > 0 &&
                                ` We couldn't use: ${unclear.join(' ')}`}
                        </p>
                    )}
                </section>

                <form
                    className="grid gap-8"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/setup/guide', { preserveScroll: true });
                    }}
                >
                    <fieldset className="grid gap-3">
                        <legend className="mb-2 text-xl font-semibold">
                            1. How do you pay your agents?
                        </legend>
                        <div className="flex flex-wrap gap-3">
                            <Choice
                                value="split"
                                current={form.data.pay_mode}
                                onPick={(v) => set('pay_mode', v)}
                            >
                                A split (like 80/20)
                            </Choice>
                            <Choice
                                value="flat"
                                current={form.data.pay_mode}
                                onPick={(v) => set('pay_mode', v)}
                            >
                                A flat fee per deal
                            </Choice>
                        </div>
                        {form.data.pay_mode === 'split' && (
                            <div className="flex flex-wrap gap-4">
                                <label className="grid gap-1 text-base">
                                    Agent's share (%)
                                    <Input
                                        type="number"
                                        min={0}
                                        max={100}
                                        className="w-32"
                                        value={form.data.agent_percent ?? ''}
                                        onChange={(e) =>
                                            set(
                                                'agent_percent',
                                                e.target.value === ''
                                                    ? undefined
                                                    : Number(e.target.value),
                                            )
                                        }
                                    />
                                </label>
                                <label className="grid gap-1 text-base">
                                    Yearly cap ($, if any)
                                    <Input
                                        type="number"
                                        min={0}
                                        className="w-40"
                                        value={form.data.cap_dollars ?? ''}
                                        onChange={(e) =>
                                            set(
                                                'cap_dollars',
                                                e.target.value === ''
                                                    ? undefined
                                                    : Number(e.target.value),
                                            )
                                        }
                                    />
                                </label>
                                <label className="grid gap-1 text-base">
                                    Fee per deal ($, if any)
                                    <Input
                                        type="number"
                                        min={0}
                                        className="w-40"
                                        value={form.data.fee_dollars ?? ''}
                                        onChange={(e) =>
                                            set(
                                                'fee_dollars',
                                                e.target.value === ''
                                                    ? undefined
                                                    : Number(e.target.value),
                                            )
                                        }
                                    />
                                </label>
                            </div>
                        )}
                        {form.data.pay_mode === 'flat' && (
                            <label className="grid gap-1 text-base">
                                What agents pay per deal ($)
                                <Input
                                    type="number"
                                    min={0}
                                    className="w-40"
                                    value={form.data.flat_fee_dollars ?? ''}
                                    onChange={(e) =>
                                        set(
                                            'flat_fee_dollars',
                                            e.target.value === ''
                                                ? undefined
                                                : Number(e.target.value),
                                        )
                                    }
                                />
                            </label>
                        )}
                        {applied.pay && (
                            <p className="text-sm text-muted-foreground">
                                {applied.pay}
                            </p>
                        )}
                        {(form.errors.agent_percent ||
                            form.errors.flat_fee_dollars) && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {form.errors.agent_percent ??
                                    form.errors.flat_fee_dollars}
                            </p>
                        )}
                    </fieldset>

                    <fieldset className="grid gap-3">
                        <legend className="mb-2 text-xl font-semibold">
                            2. Who should new company leads go to?
                        </legend>
                        <div className="flex flex-wrap gap-3">
                            {Object.entries(routing).map(([k, label]) => (
                                <Choice
                                    key={k}
                                    value={
                                        k as NonNullable<
                                            Answers['lead_routing']
                                        >
                                    }
                                    current={form.data.lead_routing}
                                    onPick={(v) => set('lead_routing', v)}
                                >
                                    {label}
                                </Choice>
                            ))}
                        </div>
                        {applied.leads && (
                            <p className="text-sm text-muted-foreground">
                                {applied.leads}
                            </p>
                        )}
                    </fieldset>

                    <fieldset className="grid gap-3">
                        <legend className="mb-2 text-xl font-semibold">
                            3. Should the assistant answer new leads within a
                            minute?
                        </legend>
                        <p className="text-sm text-muted-foreground">
                            It says it is your AI assistant, only texts people
                            who agreed to texts, between 8am and 9pm, and hands
                            over to an agent when they're ready.
                        </p>
                        <div className="flex flex-wrap gap-3">
                            <Choice
                                value={true}
                                current={form.data.ai_first_reply}
                                onPick={(v) => set('ai_first_reply', v)}
                            >
                                Yes
                            </Choice>
                            <Choice
                                value={false}
                                current={form.data.ai_first_reply}
                                onPick={(v) => set('ai_first_reply', v)}
                            >
                                No, my agents will
                            </Choice>
                        </div>
                        {applied.first_reply && (
                            <p className="text-sm text-muted-foreground">
                                {applied.first_reply}
                            </p>
                        )}
                    </fieldset>

                    <fieldset className="grid gap-3">
                        <legend className="mb-2 text-xl font-semibold">
                            4. What should your website look like?
                        </legend>
                        <div className="flex flex-wrap gap-3">
                            <Choice
                                value="light"
                                current={form.data.website_style}
                                onPick={(v) => set('website_style', v)}
                            >
                                Light and clean
                            </Choice>
                            <Choice
                                value="dark"
                                current={form.data.website_style}
                                onPick={(v) => set('website_style', v)}
                            >
                                Dark and elegant
                            </Choice>
                            <Choice
                                value="warm"
                                current={form.data.website_style}
                                onPick={(v) => set('website_style', v)}
                            >
                                Warm and friendly
                            </Choice>
                        </div>
                        {applied.website && (
                            <p className="text-sm text-muted-foreground">
                                {applied.website}
                            </p>
                        )}
                    </fieldset>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="submit"
                            size="lg"
                            disabled={form.processing}
                        >
                            Set it up
                        </Button>
                        <Badge variant="secondary">
                            You can change any answer later
                        </Badge>
                    </div>
                </form>

                <p className="text-base">
                    Next:{' '}
                    <Link className="underline" href="/setup/import">
                        bring your old files
                    </Link>{' '}
                    (people, agents and deals).
                </p>
            </div>
        </>
    );
}

SetupGuide.layout = {
    breadcrumbs: [{ title: 'Guided setup', href: '/setup/guide' }],
};
