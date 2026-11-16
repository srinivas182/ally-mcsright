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

export default function MovewiseIntroduction(props: Props) {
    const { brief } = props;
    const [reason, setReason] = useState('');
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
                {props.status === 'offered' && (
                    <div className="flex gap-2">
                        <Button onClick={() => act('accept')}>
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
