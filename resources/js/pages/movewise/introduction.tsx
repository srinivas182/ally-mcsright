import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Brief = {
    name: string | null;
    lead_type: string;
    facts: string[];
    readiness: { score: number; reasons: string[] };
    property: string | null;
    listing_url: string | null;
    saved: string[];
    say_first: string;
    method: string;
    slot_at: string | null;
    consent: string;
    email: string | null;
    phone: string | null;
    chat: string[];
};

type Props = {
    ulid: string;
    status: string;
    accept_by: string;
    credits: number;
    model: string;
    refund_status: string | null;
    refund_days: number;
    brief: Brief;
    relay: string | null;
    paused_until: string | null;
    handover_asked: boolean;
    handed_over: boolean;
    stage: string | null;
    stages: Record<string, string>;
    referral: string | null;
    referral_signed: boolean;
    history: { stage: string; note: string | null; at: string }[];
    review: {
        rating: number;
        text: string | null;
        public: boolean;
        reply: string | null;
    } | null;
};

const HOW: Record<string, string> = {
    text: 'Text them',
    call: 'Call them',
    time: 'Call at the time they booked',
};

function Countdown({ until }: { until: string }) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const t = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(t);
    }, []);
    const left = Math.max(
        0,
        Math.floor((new Date(until).getTime() - now) / 1000),
    );
    const h = Math.floor(left / 3600);
    const m = Math.floor((left % 3600) / 60);
    const s = left % 60;

    return (
        <span aria-live="polite" className="font-mono">
            {h > 0 ? `${h}h ` : ''}
            {m}m {String(s).padStart(2, '0')}s
        </span>
    );
}

function ReviewBox({
    ulid,
    review,
}: {
    ulid: string;
    review: NonNullable<Props['review']>;
}) {
    const [reply, setReply] = useState('');

    return (
        <section className="grid gap-2 rounded-xl border p-3 text-sm">
            <strong>
                Their rating: {'★'.repeat(review.rating)}
                {'☆'.repeat(5 - review.rating)}{' '}
                {review.public ? '(on your Movewise profile)' : '(private)'}
            </strong>
            {review.text && <p>{review.text}</p>}
            {review.reply ? (
                <p className="rounded bg-muted p-2">
                    Your reply: {review.reply}
                </p>
            ) : (
                review.public && (
                    <div className="grid gap-2">
                        <textarea
                            aria-label="Your public reply"
                            className="min-h-16 rounded-md border bg-background p-2"
                            maxLength={1000}
                            value={reply}
                            onChange={(e) => setReply(e.target.value)}
                        />
                        <div>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/movewise/introductions/${ulid}`,
                                        { action: 'reply', reply },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Reply publicly (once)
                            </Button>
                        </div>
                    </div>
                )
            )}
        </section>
    );
}

export default function MovewiseIntroduction(props: Props) {
    const { brief } = props;
    const [reason, setReason] = useState('');
    const [signed, setSigned] = useState(false);
    const [agree, setAgree] = useState(false);
    const [update, setUpdate] = useState({
        stage: '',
        note: '',
        close_price: '',
        commission: '',
        close_date: '',
    });
    const act = (action: string, extra: Record<string, string> = {}) =>
        router.post(
            `/movewise/introductions/${props.ulid}`,
            { action, ...extra },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Movewise introduction" />
            <div className="grid max-w-3xl gap-4 p-4">
                <PageHeader
                    title={`${brief.name ?? 'Someone'} chose you`}
                    description={`A ${brief.lead_type} on Movewise. Nobody else was told about them.`}
                />
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <Badge>{props.status}</Badge>
                    <Badge variant="outline">
                        Readiness {brief.readiness.score}/100
                    </Badge>
                    {props.status === 'offered' && (
                        <span>
                            Accept within <Countdown until={props.accept_by} />
                        </span>
                    )}
                </div>
                {props.status === 'offered' && props.referral && (
                    <label className="flex items-start gap-2 rounded-xl border p-3 text-sm">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={agree}
                            onChange={(e) => setAgree(e.target.checked)}
                        />
                        <span>
                            I sign this referral agreement for my brokerage:{' '}
                            <span className="text-muted-foreground">
                                {props.referral}
                            </span>
                        </span>
                    </label>
                )}
                {props.status === 'offered' && (
                    <div className="flex gap-2">
                        <Button
                            onClick={() =>
                                act(
                                    'accept',
                                    agree ? { agree_referral: '1' } : {},
                                )
                            }
                        >
                            Accept the introduction
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => act('decline')}
                        >
                            I can't take this one
                        </Button>
                    </div>
                )}
                {props.status === 'accepted' && (
                    <section className="grid gap-1 rounded-xl border p-3 text-sm">
                        <strong>{HOW[brief.method] ?? 'Contact them'}</strong>
                        {brief.phone && <span>Mobile: {brief.phone}</span>}
                        {brief.email && <span>Email: {brief.email}</span>}
                        {brief.slot_at && (
                            <span>
                                Booked:{' '}
                                {new Date(brief.slot_at).toLocaleString()}
                            </span>
                        )}
                        <span className="text-muted-foreground">
                            {props.credits > 0
                                ? `${props.credits} credits charged.`
                                : 'Nothing charged up front.'}{' '}
                            They are in your contacts with this brief.
                        </span>
                    </section>
                )}
                {props.status === 'accepted' && (
                    <section className="grid gap-2 rounded-xl border p-3 text-sm">
                        {props.relay ? (
                            <p>
                                Text them from your RightAlly inbox: messages go
                                through their Movewise number, {props.relay}, in
                                one thread. Personal messages only: they can't
                                be added to campaigns, plans or automated texts.
                            </p>
                        ) : props.handed_over ? (
                            <p>
                                They agreed to move to your own number. Movewise
                                has stepped back.
                            </p>
                        ) : null}
                        {props.paused_until && (
                            <p className="font-medium">
                                They paused contact until{' '}
                                {new Date(
                                    props.paused_until,
                                ).toLocaleDateString()}
                                . Messages are held until then.
                            </p>
                        )}
                        {props.relay && !props.handover_asked && (
                            <div className="grid gap-2">
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={signed}
                                        onChange={(e) =>
                                            setSigned(e.target.checked)
                                        }
                                    />
                                    We've signed an agreement, or the deal has
                                    closed
                                </label>
                                <div>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={!signed}
                                        onClick={() =>
                                            act('handover', {
                                                signed_or_closed: signed
                                                    ? '1'
                                                    : '',
                                            })
                                        }
                                    >
                                        Ask to move to my own number
                                    </Button>
                                </div>
                            </div>
                        )}
                        {props.handover_asked && !props.handed_over && (
                            <p className="text-muted-foreground">
                                Asked to move to your own number. Waiting for
                                them to reply YES.
                            </p>
                        )}
                    </section>
                )}
                {props.status === 'accepted' &&
                    !['closed', 'lost'].includes(props.stage ?? '') && (
                        <form
                            className="grid gap-2 rounded-xl border p-3 text-sm md:grid-cols-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                act('status', update);
                            }}
                        >
                            <strong className="md:col-span-2">
                                Where things stand{' '}
                                {props.stage
                                    ? `(now: ${props.stages[props.stage]})`
                                    : ''}
                                . Update at least every 14 days.
                            </strong>
                            <select
                                aria-label="Status"
                                className="h-9 rounded-md border bg-background px-2"
                                value={update.stage}
                                onChange={(e) =>
                                    setUpdate({
                                        ...update,
                                        stage: e.target.value,
                                    })
                                }
                            >
                                <option value="">Choose</option>
                                {Object.entries(props.stages).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                            <Input
                                aria-label="Note"
                                placeholder="Note (optional)"
                                value={update.note}
                                onChange={(e) =>
                                    setUpdate({
                                        ...update,
                                        note: e.target.value,
                                    })
                                }
                            />
                            {update.stage === 'closed' && (
                                <>
                                    <Input
                                        aria-label="Sale price"
                                        placeholder="Sale price ($)"
                                        inputMode="decimal"
                                        value={update.close_price}
                                        onChange={(e) =>
                                            setUpdate({
                                                ...update,
                                                close_price: e.target.value,
                                            })
                                        }
                                    />
                                    <Input
                                        aria-label="Your side's gross commission"
                                        placeholder="Your side's gross commission ($)"
                                        inputMode="decimal"
                                        value={update.commission}
                                        onChange={(e) =>
                                            setUpdate({
                                                ...update,
                                                commission: e.target.value,
                                            })
                                        }
                                    />
                                    <Input
                                        aria-label="Closing date"
                                        type="date"
                                        value={update.close_date}
                                        onChange={(e) =>
                                            setUpdate({
                                                ...update,
                                                close_date: e.target.value,
                                            })
                                        }
                                    />
                                </>
                            )}
                            <div className="md:col-span-2">
                                <Button
                                    size="sm"
                                    disabled={update.stage === ''}
                                >
                                    Update status
                                </Button>
                            </div>
                        </form>
                    )}
                {props.review && (
                    <ReviewBox ulid={props.ulid} review={props.review} />
                )}
                {props.history.length > 0 && (
                    <ol className="grid gap-1 text-sm text-muted-foreground">
                        {props.history.map((h, i) => (
                            <li key={i}>
                                {new Date(h.at).toLocaleDateString()}: {h.stage}
                                {h.note ? ` (${h.note})` : ''}
                            </li>
                        ))}
                    </ol>
                )}
                <section className="grid gap-2 text-sm">
                    <h2 className="text-base font-semibold">
                        What they told us
                    </h2>
                    <ul className="list-disc pl-5">
                        {brief.facts.map((f) => (
                            <li key={f}>{f}</li>
                        ))}
                    </ul>
                    {brief.property && (
                        <p>
                            Home:{' '}
                            {brief.listing_url ? (
                                <a
                                    className="underline"
                                    href={brief.listing_url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    {brief.property}
                                </a>
                            ) : (
                                brief.property
                            )}
                        </p>
                    )}
                    {brief.saved.length > 0 && (
                        <p>Saved homes: {brief.saved.join('; ')}</p>
                    )}
                    <p className="text-muted-foreground">
                        Readiness: {brief.readiness.reasons.join('; ')}
                    </p>
                    <h2 className="text-base font-semibold">
                        What to say first
                    </h2>
                    <p className="rounded-lg bg-muted p-2">{brief.say_first}</p>
                    <h2 className="text-base font-semibold">
                        What they agreed to
                    </h2>
                    <p className="text-muted-foreground">{brief.consent}</p>
                    <h2 className="text-base font-semibold">
                        Their conversation with AIlly
                    </h2>
                    {brief.chat.length === 0 ? (
                        <p className="text-muted-foreground">None yet.</p>
                    ) : (
                        <ol className="grid gap-1">
                            {brief.chat.map((l, i) => (
                                <li key={i} className="rounded bg-muted/50 p-1">
                                    {l}
                                </li>
                            ))}
                        </ol>
                    )}
                </section>
                {props.status === 'accepted' &&
                    props.credits > 0 &&
                    props.refund_status === null && (
                        <section className="grid gap-2 rounded-xl border p-3 text-sm">
                            <strong>A problem with this introduction?</strong>
                            <p className="text-muted-foreground">
                                Within {props.refund_days} days, tell us if the
                                number isn't theirs or they say they never
                                asked. Movewise checks and refunds.
                            </p>
                            <Input
                                aria-label="What went wrong"
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                            />
                            <div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => act('report', { reason })}
                                >
                                    Ask for a refund
                                </Button>
                            </div>
                        </section>
                    )}
                {props.refund_status && (
                    <p className="text-sm text-muted-foreground">
                        Refund: {props.refund_status}
                    </p>
                )}
            </div>
        </>
    );
}
