import { Head, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { SummaryBody } from '@/components/forms/summary-panel';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    token: string;
    brokerage: string;
    packet: { title: string; status: string; verify: string };
    signer: {
        name: string;
        consented: boolean;
        verified: boolean;
        signed: boolean;
        phone_last4: string | null;
        id_required: boolean;
        id_status: string | null;
    };
    fields: { form: string; label: string; type: string }[];
    disclosure: string;
    summary: {
        en: SummaryLang;
        es: SummaryLang;
        deadlines: { label: string; date: string; rule: string }[];
    } | null;
    changes: {
        id: number;
        form: string;
        label: string;
        old: string;
        new: string;
        reason: string;
    }[];
};

type SummaryLang = {
    headline: string;
    points: { topic: string; text: string }[];
    agreeing_to: string[];
    disclaimer: string;
};

/** The approved plain-English summary, in English or Spanish, before the signer reviews the documents. */
function PlainSummary({ summary }: { summary: NonNullable<Props['summary']> }) {
    const [lang, setLang] = useState<'en' | 'es'>('en');
    const s = summary[lang];

    return (
        <section className="grid gap-2 rounded-lg border bg-muted/30 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-semibold">
                    {lang === 'en'
                        ? 'In plain English'
                        : 'En palabras sencillas'}
                </h2>
                <div className="ml-auto flex gap-1" role="tablist">
                    {(['en', 'es'] as const).map((l) => (
                        <Button
                            key={l}
                            size="sm"
                            role="tab"
                            aria-selected={lang === l}
                            variant={lang === l ? 'secondary' : 'ghost'}
                            onClick={() => setLang(l)}
                        >
                            {l === 'en' ? 'English' : 'Español'}
                        </Button>
                    ))}
                </div>
            </div>
            <SummaryBody
                headline={s.headline}
                points={s.points}
                agreeing={s.agreeing_to}
            />
            {summary.deadlines.some((d) => d.date) && (
                <div>
                    <p className="text-xs text-muted-foreground">
                        {lang === 'en' ? 'Key dates' : 'Fechas clave'}
                    </p>
                    <ul>
                        {summary.deadlines
                            .filter((d) => d.date)
                            .map((d, i) => (
                                <li key={i}>
                                    {d.label}: {d.date}
                                </li>
                            ))}
                    </ul>
                </div>
            )}
            <p className="text-xs text-muted-foreground">{s.disclaimer}</p>
        </section>
    );
}

/** A signer who already signed initials only what changed since. */
function InitialChanges({
    base,
    changes,
}: {
    base: string;
    changes: Props['changes'];
}) {
    const ack = useForm({ initials: '' });
    const decline = useForm({ reason: '' });
    const [declining, setDeclining] = useState(false);
    const show = (v: string) => (v === '' ? '(blank)' : v);

    return (
        <section className="grid gap-3 rounded-lg border border-sky-300 bg-sky-50/50 p-4">
            <h2 className="font-semibold">What changed since you signed</h2>
            <p>
                Your signature stays on everything else. Please review and
                initial only these changes.
            </p>
            <ul className="grid gap-2">
                {changes.map((c) => (
                    <li key={c.id} className="rounded border bg-background p-2">
                        <p className="font-medium">
                            {c.label}{' '}
                            <span className="text-xs text-muted-foreground">
                                ({c.form})
                            </span>
                        </p>
                        <p>
                            <span className="line-through">{show(c.old)}</span>{' '}
                            → <strong>{show(c.new)}</strong>
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Reason: {c.reason}
                        </p>
                    </li>
                ))}
            </ul>
            <a
                className="underline"
                href={`${base}/document.pdf`}
                target="_blank"
                rel="noreferrer"
            >
                See the documents with the changes
            </a>
            <form
                className="flex flex-wrap items-center gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    ack.post(`${base}/acknowledge`);
                }}
            >
                <Input
                    aria-label="Your initials"
                    placeholder="Initials"
                    maxLength={5}
                    className="w-28"
                    value={ack.data.initials}
                    onChange={(e) => ack.setData('initials', e.target.value)}
                />
                <Button
                    type="submit"
                    disabled={ack.processing || !ack.data.initials}
                >
                    Initial{' '}
                    {changes.length === 1 ? 'this change' : 'these changes'}
                </Button>
            </form>
            {ack.errors.initials && (
                <p role="alert" className="text-destructive">
                    {ack.errors.initials}
                </p>
            )}
            {declining ? (
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        decline.post(`${base}/decline`);
                    }}
                >
                    <Input
                        aria-label="Why don't you agree?"
                        placeholder="Why don't you agree?"
                        className="w-72"
                        value={decline.data.reason}
                        onChange={(e) =>
                            decline.setData('reason', e.target.value)
                        }
                    />
                    <Button type="submit" variant="outline">
                        Decline the changes
                    </Button>
                </form>
            ) : (
                <button
                    type="button"
                    className="justify-self-start text-xs underline"
                    onClick={() => setDeclining(true)}
                >
                    I don't agree with these changes
                </button>
            )}
        </section>
    );
}

function SignaturePad({
    onChange,
}: {
    onChange: (dataUrl: string | null) => void;
}) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);
    const point = (e: React.PointerEvent<HTMLCanvasElement>) => {
        const r = e.currentTarget.getBoundingClientRect();
        return [e.clientX - r.left, e.clientY - r.top] as const;
    };

    return (
        <div className="grid gap-1">
            <canvas
                ref={canvas}
                width={400}
                height={120}
                aria-label="Draw your signature"
                className="touch-none rounded border bg-white"
                onPointerDown={(e) => {
                    drawing.current = true;
                    const ctx = e.currentTarget.getContext('2d');
                    const [x, y] = point(e);
                    ctx?.beginPath();
                    ctx?.moveTo(x, y);
                }}
                onPointerMove={(e) => {
                    if (!drawing.current) return;
                    const ctx = e.currentTarget.getContext('2d');
                    if (!ctx) return;
                    const [x, y] = point(e);
                    ctx.lineWidth = 2;
                    ctx.strokeStyle = '#0f1750';
                    ctx.lineTo(x, y);
                    ctx.stroke();
                }}
                onPointerUp={() => {
                    drawing.current = false;
                    onChange(canvas.current?.toDataURL('image/png') ?? null);
                }}
            />
            <button
                type="button"
                className="justify-self-start text-xs underline"
                onClick={() => {
                    canvas.current?.getContext('2d')?.clearRect(0, 0, 400, 120);
                    onChange(null);
                }}
            >
                Clear
            </button>
        </div>
    );
}

/** What a signer sees: consent, (text code), review, sign or decline. */
export default function Sign({
    token,
    brokerage,
    packet,
    signer,
    fields,
    disclosure,
    summary,
    changes,
}: Props) {
    const base = `/sign/${token}`;
    const consent = useForm({ agree: false });
    const code = useForm({ code: '' });
    const sign = useForm<{
        typed: string;
        drawn: string | null;
        initials: string;
    }>({ typed: '', drawn: null, initials: '' });
    const [mode, setMode] = useState<'type' | 'draw'>('type');
    const [declining, setDeclining] = useState(false);
    const decline = useForm({ reason: '' });
    const done = signer.signed || packet.status !== 'sent';

    return (
        <>
            <Head title={`Sign: ${packet.title}`} />
            <main className="mx-auto grid max-w-2xl gap-4 p-6 text-sm">
                <h1 className="text-xl font-semibold">{packet.title}</h1>
                <p>
                    From {brokerage}, for {signer.name}.
                </p>
                <p
                    role="note"
                    className="rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-900"
                >
                    Never wire money based on an email or text. Payment
                    instructions never change by email — call your agent or the
                    title company on a number you already know.
                </p>

                {changes.length > 0 && packet.status === 'sent' ? (
                    <InitialChanges base={base} changes={changes} />
                ) : done ? (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <p className="font-medium">
                            {packet.status === 'completed'
                                ? 'Everyone has signed. Your copy has been emailed to you.'
                                : signer.signed
                                  ? 'Thank you — you have signed. We will email your copy when everyone has signed.'
                                  : packet.status === 'declined'
                                    ? 'This was declined.'
                                    : 'This is no longer available for signing.'}
                        </p>
                        {packet.status === 'completed' && (
                            <a
                                className="underline"
                                href={`${base}/document.pdf`}
                            >
                                Download the signed copy
                            </a>
                        )}
                    </section>
                ) : !signer.consented ? (
                    <form
                        className="grid gap-2 rounded-lg border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            consent.post(`${base}/consent`);
                        }}
                    >
                        <h2 className="font-semibold">
                            Agree to sign electronically
                        </h2>
                        <p>{disclosure}</p>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={consent.data.agree}
                                onChange={(e) =>
                                    consent.setData('agree', e.target.checked)
                                }
                            />{' '}
                            I agree
                        </label>
                        <Button type="submit" disabled={!consent.data.agree}>
                            Continue
                        </Button>
                    </form>
                ) : packet.verify === 'sms' && !signer.verified ? (
                    <form
                        className="grid gap-2 rounded-lg border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            code.post(`${base}/verify`);
                        }}
                    >
                        <h2 className="font-semibold">
                            Enter the code we texted to the number ending{' '}
                            {signer.phone_last4}
                        </h2>
                        <Input
                            aria-label="Code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            className="w-40"
                            value={code.data.code}
                            onChange={(e) =>
                                code.setData('code', e.target.value)
                            }
                        />
                        <span className="flex gap-2">
                            <Button type="submit">Check code</Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => router.post(`${base}/code`)}
                            >
                                Send a new code
                            </Button>
                        </span>
                        {code.errors.code && (
                            <p role="alert" className="text-destructive">
                                {code.errors.code}
                            </p>
                        )}
                    </form>
                ) : signer.id_required && signer.id_status !== 'verified' ? (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <h2 className="font-semibold">Verify your identity</h2>
                        {signer.id_status === 'name_mismatch' ||
                        signer.id_status === 'owner_mismatch' ? (
                            <p role="alert" className="text-destructive">
                                {signer.id_status === 'name_mismatch'
                                    ? 'The name on your ID does not match these documents.'
                                    : 'We need to confirm ownership of the property before you sign.'}{' '}
                                Your agent has been told and will be in touch.
                            </p>
                        ) : (
                            <>
                                <p>
                                    To protect you and the property from fraud,
                                    please verify your identity with a
                                    government photo ID and a quick selfie. It
                                    takes about two minutes on your phone. Your
                                    ID images are held by the verification
                                    service, not shared with us.
                                </p>
                                {signer.id_status === 'failed' && (
                                    <p
                                        role="alert"
                                        className="text-destructive"
                                    >
                                        That check did not go through. Please
                                        try again in good light.
                                    </p>
                                )}
                                <Button
                                    className="justify-self-start"
                                    onClick={() =>
                                        router.post(`${base}/identity`)
                                    }
                                >
                                    {signer.id_status === 'failed'
                                        ? 'Try again'
                                        : 'Verify my identity'}
                                </Button>
                            </>
                        )}
                    </section>
                ) : (
                    <>
                        {summary && <PlainSummary summary={summary} />}
                        <section className="grid gap-2 rounded-lg border p-4">
                            <h2 className="font-semibold">Review</h2>
                            <iframe
                                title="Documents to sign"
                                src={`${base}/document.pdf`}
                                className="h-96 w-full rounded border"
                            />
                            <a
                                className="underline"
                                href={`${base}/document.pdf`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Open in a new tab
                            </a>
                            <p className="text-muted-foreground">
                                You will sign:{' '}
                                {fields
                                    .map((f) => `${f.label} (${f.form})`)
                                    .join('; ')}
                                .
                            </p>
                        </section>
                        <form
                            className="grid gap-2 rounded-lg border p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                sign.post(`${base}/sign`);
                            }}
                        >
                            <h2 className="font-semibold">Sign</h2>
                            <div
                                role="group"
                                aria-label="How to sign"
                                className="flex w-fit rounded border"
                            >
                                {(['type', 'draw'] as const).map((m) => (
                                    <button
                                        key={m}
                                        type="button"
                                        aria-pressed={mode === m}
                                        className={`px-3 py-1.5 ${mode === m ? 'bg-muted font-medium' : ''}`}
                                        onClick={() => {
                                            setMode(m);
                                            sign.setData(
                                                m === 'type'
                                                    ? 'drawn'
                                                    : 'typed',
                                                m === 'type' ? null : '',
                                            );
                                        }}
                                    >
                                        {m === 'type' ? 'Type' : 'Draw'}
                                    </button>
                                ))}
                            </div>
                            {mode === 'type' ? (
                                <Input
                                    aria-label="Type your full name"
                                    placeholder={`Type “${signer.name}”`}
                                    className="max-w-sm font-serif italic"
                                    value={sign.data.typed}
                                    onChange={(e) =>
                                        sign.setData('typed', e.target.value)
                                    }
                                />
                            ) : (
                                <SignaturePad
                                    onChange={(d) => sign.setData('drawn', d)}
                                />
                            )}
                            <Input
                                aria-label="Initials"
                                placeholder="Initials"
                                maxLength={5}
                                className="w-28"
                                value={sign.data.initials}
                                onChange={(e) =>
                                    sign.setData('initials', e.target.value)
                                }
                            />
                            <Button
                                type="submit"
                                disabled={
                                    sign.processing ||
                                    !sign.data.initials ||
                                    (mode === 'type'
                                        ? !sign.data.typed
                                        : !sign.data.drawn)
                                }
                            >
                                Sign
                            </Button>
                            {Object.values(sign.errors)[0] && (
                                <p role="alert" className="text-destructive">
                                    {Object.values(sign.errors)[0]}
                                </p>
                            )}
                        </form>
                        {declining ? (
                            <form
                                className="flex flex-wrap gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    decline.post(`${base}/decline`);
                                }}
                            >
                                <Input
                                    aria-label="Why are you declining?"
                                    placeholder="Why are you declining?"
                                    className="w-72"
                                    value={decline.data.reason}
                                    onChange={(e) =>
                                        decline.setData(
                                            'reason',
                                            e.target.value,
                                        )
                                    }
                                />
                                <Button type="submit" variant="outline">
                                    Decline
                                </Button>
                            </form>
                        ) : (
                            <button
                                type="button"
                                className="justify-self-start text-xs underline"
                                onClick={() => setDeclining(true)}
                            >
                                I don't want to sign
                            </button>
                        )}
                    </>
                )}
            </main>
        </>
    );
}
