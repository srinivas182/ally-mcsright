import { Head, useForm, usePage } from '@inertiajs/react';
import { Check, CircleCheck, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PhoneInput } from '@/components/ui/number-inputs';
import { usd } from '@/lib/cents';

type Props = {
    brokerage: string;
    sponsor: { name: string; since: string | null } | null;
    root: string | null;
    suggested: boolean;
    page: {
        headline: string;
        pitch: string;
        reasons: string[];
        video: string | null;
    };
    code: string | null;
    unknownCode: boolean;
    packs: {
        ulid: string;
        name: string;
        description: string | null;
        price_cents: number;
    }[];
    plans: {
        ulid: string;
        name: string;
        description: string | null;
        lines: string[];
        default: boolean;
    }[];
    states: Record<string, string>;
    cancelled: boolean;
};

const EMAIL = /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i;

function Field({
    label,
    hint,
    error,
    children,
}: {
    label: string;
    hint?: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <label className="grid content-start gap-1.5 text-[15px] font-medium text-slate-800">
            {label}
            {children}
            {hint && !error && (
                <span className="text-sm font-normal text-slate-500">
                    {hint}
                </span>
            )}
            {error && (
                <span
                    role="alert"
                    className="text-sm font-normal text-destructive"
                >
                    {error}
                </span>
            )}
        </label>
    );
}

function Step({ n, title }: { n: number; title: string }) {
    return (
        <h2 className="flex items-center gap-3 text-lg font-semibold text-slate-900">
            <span className="grid size-8 place-items-center rounded-full bg-[#0E1B2C] text-sm text-[#E9D9B4]">
                {n}
            </span>
            {title}
        </h2>
    );
}

function Initials({ name, small = false }: { name: string; small?: boolean }) {
    return (
        <span
            aria-hidden="true"
            className={
                small
                    ? 'grid size-9 shrink-0 place-items-center rounded-full bg-[#E9D9B4] text-sm font-semibold text-[#0E1B2C]'
                    : 'grid size-14 shrink-0 place-items-center rounded-full bg-[#E9D9B4] text-lg font-semibold text-[#0E1B2C] ring-2 ring-[#C8A96A] ring-offset-2 ring-offset-[#0E1B2C]'
            }
        >
            {name
                .split(' ')
                .filter(Boolean)
                .slice(0, 2)
                .map((p) => p[0]?.toUpperCase())
                .join('')}
        </span>
    );
}

/**
 * CM4/CM4b: join a brokerage through an agent's link. On the left, the brokerage's opportunity (its words, video
 * and reasons) and the sponsor; on the right, three steps: about you, the licence (checked with the state), the
 * pack. A valid licence and the payment place the new agent straight away, under their sponsor.
 */
export default function Join({
    brokerage,
    sponsor,
    root,
    suggested,
    page,
    code,
    unknownCode,
    packs,
    plans,
    states,
    cancelled,
}: Props) {
    const { tenant } = usePage().props;
    const form = useForm({
        referral: '',
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
        licence_state:
            Object.keys(states).length === 1 ? Object.keys(states)[0] : '',
        licence_number: '',
        pack: packs[0]?.ulid ?? '',
        plan: plans.length === 1 ? plans[0].ulid : '',
        agree: false,
    });
    const [phoneShown, setPhoneShown] = useState('');
    // FX4: "Change" on Referred by: find an agent by phone, email or code.
    const [changing, setChanging] = useState(false);
    const [contact, setContact] = useState('');
    const [lookup, setLookup] = useState<
        | { state: 'idle' | 'busy' }
        | { state: 'found'; name: string; code: string }
        | { state: 'none' }
    >({ state: 'idle' });
    const [picked, setPicked] = useState<{ name: string; code: string } | null>(
        null,
    );
    const referrerName = picked?.name ?? sponsor?.name ?? root ?? brokerage;
    const referrerNote = picked
        ? 'The agent you chose'
        : sponsor
          ? suggested
              ? 'From the agent page you visited'
              : 'Your sponsor, from their link'
          : `${brokerage} company account. Agents with no sponsor join here.`;
    const find = async () => {
        if (contact.trim() === '') {
            return;
        }
        setLookup({ state: 'busy' });
        const token = decodeURIComponent(
            document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
        );
        try {
            const r = await fetch('/join/referrer', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': token,
                },
                body: JSON.stringify({ contact }),
            });
            const d = (await r.json()) as {
                found?: boolean;
                name?: string;
                code?: string;
            };
            setLookup(
                r.ok && d.found && d.name && d.code
                    ? { state: 'found', name: d.name, code: d.code }
                    : { state: 'none' },
            );
        } catch {
            setLookup({ state: 'none' });
        }
    };
    const [touched, setTouched] = useState<Record<string, boolean>>({});
    const touch = (k: string) => setTouched((t) => ({ ...t, [k]: true }));
    const local: Record<string, string | undefined> = {
        email:
            touched.email && form.data.email && !EMAIL.test(form.data.email)
                ? 'Enter a full email address, like name@example.com.'
                : undefined,
        phone:
            touched.phone && form.data.phone === '' && phoneShown !== ''
                ? 'Enter a 10-digit US phone number.'
                : undefined,
        password:
            touched.password &&
            form.data.password &&
            form.data.password.length < 12
                ? 'Use 12 or more characters.'
                : undefined,
        password_confirmation:
            touched.password_confirmation &&
            form.data.password_confirmation &&
            form.data.password_confirmation !== form.data.password
                ? 'The two passwords are not the same.'
                : undefined,
    };
    const e = {
        ...local,
        ...(form.errors as Record<string, string | undefined>),
    };
    const ready =
        EMAIL.test(form.data.email) &&
        form.data.phone !== '' &&
        form.data.password.length >= 12 &&
        form.data.password === form.data.password_confirmation &&
        (plans.length === 0 || form.data.plan !== '') &&
        form.data.agree;
    const steps = 2 + (plans.length > 0 ? 1 : 0) + (packs.length > 0 ? 1 : 0);
    const stepWords = ['', '', 'two', 'three', 'four'];
    const logo = (tenant as { logo?: string | null } | null)?.logo ?? null;

    return (
        <>
            <Head title={`Join ${brokerage}`}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main className="min-h-screen bg-[#F6F3EC] lg:grid lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
                <aside className="relative overflow-hidden bg-[#0E1B2C] px-6 py-10 text-white sm:px-10 lg:sticky lg:top-0 lg:h-screen lg:overflow-y-auto lg:py-14">
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute -top-40 -right-40 size-[28rem] rounded-full bg-[#C8A96A]/10 blur-3xl"
                    />
                    <div className="relative mx-auto grid max-w-xl gap-8">
                        <div className="flex items-center gap-3">
                            {logo ? (
                                <img
                                    src={logo}
                                    alt={brokerage}
                                    className="h-10 w-auto max-w-[220px] object-contain"
                                />
                            ) : (
                                <span className="text-lg font-semibold tracking-wide text-[#E9D9B4]">
                                    {brokerage}
                                </span>
                            )}
                        </div>
                        <div className="grid gap-4">
                            <h1 className="font-serif text-4xl leading-tight font-semibold text-balance sm:text-5xl">
                                {page.headline}
                            </h1>
                            {page.pitch && (
                                <p className="text-lg leading-relaxed text-slate-300">
                                    {page.pitch}
                                </p>
                            )}
                        </div>
                        {page.video && (
                            <div className="overflow-hidden rounded-2xl border border-white/10 shadow-2xl">
                                {page.video.endsWith('.mp4') ? (
                                    <video
                                        controls
                                        className="aspect-video w-full bg-black"
                                        src={page.video}
                                        title={`Why ${brokerage}`}
                                    >
                                        <track
                                            kind="captions"
                                            src={page.video.replace(
                                                /\.mp4$/i,
                                                '.vtt',
                                            )}
                                            srcLang="en"
                                            label="English"
                                            default
                                        />
                                    </video>
                                ) : (
                                    <iframe
                                        className="aspect-video w-full"
                                        src={page.video}
                                        title={`Why ${brokerage}`}
                                        allow="encrypted-media; picture-in-picture; fullscreen"
                                    />
                                )}
                            </div>
                        )}
                        {page.reasons.length > 0 && (
                            <ul className="grid gap-3">
                                {page.reasons.map((r) => (
                                    <li
                                        key={r}
                                        className="flex gap-3 text-[17px] text-slate-100"
                                    >
                                        <Check
                                            className="mt-0.5 size-5 shrink-0 text-[#C8A96A]"
                                            aria-hidden="true"
                                        />
                                        {r}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {sponsor && (
                            <div className="flex items-center gap-4 rounded-2xl border border-[#C8A96A]/40 bg-white/5 p-5">
                                <Initials name={sponsor.name} />
                                <div>
                                    <p className="text-sm text-[#E9D9B4]">
                                        Your sponsor
                                    </p>
                                    <p className="text-xl font-semibold">
                                        {sponsor.name}
                                    </p>
                                    <p className="text-sm text-slate-300">
                                        {sponsor.since
                                            ? `With ${brokerage} since ${sponsor.since}`
                                            : `Agent at ${brokerage}`}
                                    </p>
                                </div>
                            </div>
                        )}
                    </div>
                </aside>

                <section className="px-4 py-10 sm:px-8 lg:py-14">
                    <div className="mx-auto grid max-w-2xl gap-6">
                        <div className="grid gap-1">
                            <p className="text-sm font-semibold text-[#9A7B3F]">
                                Join {brokerage}
                            </p>
                            <p className="text-3xl font-semibold text-slate-900">
                                Start in {stepWords[steps]} steps
                            </p>
                            <p className="text-slate-600">
                                {sponsor
                                    ? `${sponsor.name} invited you. You join under them as soon as your licence checks out${packs.length > 0 ? ' and your pack is paid' : ''}.`
                                    : `You join ${brokerage} directly, and can start as soon as your licence checks out${packs.length > 0 ? ' and your pack is paid' : ''}.`}
                            </p>
                        </div>
                        {(unknownCode || cancelled) && (
                            <p
                                role="status"
                                className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
                            >
                                {cancelled
                                    ? 'The payment was not finished. Fill in the form again to continue.'
                                    : 'We could not find the agent whose link you used. You can still join; ask them to send the link again if you want them as your sponsor.'}
                            </p>
                        )}
                        {Object.keys(states).length === 0 ? (
                            <div
                                role="status"
                                className="grid gap-2 rounded-3xl border border-slate-200 bg-white p-6 text-slate-700 sm:p-8"
                            >
                                <h2 className="text-lg font-semibold text-slate-900">
                                    Joining online opens soon
                                </h2>
                                <p>
                                    {brokerage} is adding its brokerage licence.
                                    Agents can join online once it is on file.
                                    Until then, please contact {brokerage}{' '}
                                    directly.
                                </p>
                            </div>
                        ) : (
                            <form
                                noValidate
                                className="grid gap-7 rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_20px_60px_-30px_rgba(14,27,44,0.35)] sm:p-8"
                                onSubmit={(ev) => {
                                    ev.preventDefault();
                                    setTouched({
                                        email: true,
                                        phone: true,
                                        password: true,
                                        password_confirmation: true,
                                    });
                                    form.post(code ? `/join/${code}` : '/join');
                                }}
                            >
                                <Step n={1} title="About you" />
                                <div className="-mt-2 grid gap-2 text-[15px]">
                                    <span
                                        id="referred-by"
                                        className="font-medium text-slate-800"
                                    >
                                        Referred by
                                    </span>
                                    <div
                                        aria-labelledby="referred-by"
                                        className="flex min-h-11 items-center gap-3 rounded-md border border-input bg-slate-50 px-3 py-2"
                                    >
                                        <Initials name={referrerName} small />
                                        <span className="grid min-w-0 flex-1">
                                            <span className="font-semibold text-slate-900">
                                                {referrerName}
                                            </span>
                                            <span className="text-sm text-slate-500">
                                                {referrerNote}
                                            </span>
                                        </span>
                                        {!changing && (
                                            <button
                                                type="button"
                                                className="shrink-0 self-start text-sm font-medium text-[#9A7B3F] underline-offset-4 hover:underline"
                                                onClick={() => {
                                                    setChanging(true);
                                                    setLookup({
                                                        state: 'idle',
                                                    });
                                                }}
                                            >
                                                Change
                                            </button>
                                        )}
                                    </div>
                                    {changing && (
                                        <div className="grid gap-3 rounded-md border border-slate-200 p-3">
                                            <label className="grid gap-1.5 font-medium text-slate-800">
                                                Agent&apos;s mobile phone, email
                                                or referral code
                                                <span className="flex gap-2">
                                                    <Input
                                                        className="h-11"
                                                        value={contact}
                                                        onChange={(x) => {
                                                            setContact(
                                                                x.target.value,
                                                            );
                                                            setLookup({
                                                                state: 'idle',
                                                            });
                                                        }}
                                                        onKeyDown={(x) => {
                                                            if (
                                                                x.key ===
                                                                'Enter'
                                                            ) {
                                                                x.preventDefault();
                                                                void find();
                                                            }
                                                        }}
                                                        placeholder="(512) 555-0100 or name@example.com"
                                                    />
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        className="h-11"
                                                        disabled={
                                                            lookup.state ===
                                                                'busy' ||
                                                            contact.trim() ===
                                                                ''
                                                        }
                                                        onClick={() =>
                                                            void find()
                                                        }
                                                    >
                                                        Find
                                                    </Button>
                                                </span>
                                            </label>
                                            <div
                                                role="status"
                                                aria-live="polite"
                                            >
                                                {lookup.state === 'found' && (
                                                    <span className="flex flex-wrap items-center gap-3">
                                                        <Initials
                                                            name={lookup.name}
                                                            small
                                                        />
                                                        <span className="font-semibold text-slate-900">
                                                            {lookup.name}
                                                        </span>
                                                        <span className="ml-auto flex gap-2">
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                onClick={() => {
                                                                    setPicked({
                                                                        name: lookup.name,
                                                                        code: lookup.code,
                                                                    });
                                                                    form.setData(
                                                                        'referral',
                                                                        lookup.code,
                                                                    );
                                                                    setChanging(
                                                                        false,
                                                                    );
                                                                    setContact(
                                                                        '',
                                                                    );
                                                                }}
                                                            >
                                                                OK, use{' '}
                                                                {
                                                                    lookup.name.split(
                                                                        ' ',
                                                                    )[0]
                                                                }
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    setChanging(
                                                                        false,
                                                                    )
                                                                }
                                                            >
                                                                Cancel
                                                            </Button>
                                                        </span>
                                                    </span>
                                                )}
                                                {lookup.state === 'none' && (
                                                    <span className="text-sm text-amber-800">
                                                        No agent at {brokerage}{' '}
                                                        has those details. You
                                                        stay referred by{' '}
                                                        {referrerName}.
                                                    </span>
                                                )}
                                            </div>
                                            {lookup.state !== 'found' && (
                                                <button
                                                    type="button"
                                                    className="justify-self-start text-sm text-slate-500 underline-offset-4 hover:underline"
                                                    onClick={() =>
                                                        setChanging(false)
                                                    }
                                                >
                                                    Keep {referrerName}
                                                </button>
                                            )}
                                        </div>
                                    )}
                                    {e.referral && (
                                        <span
                                            role="alert"
                                            className="text-sm text-destructive"
                                        >
                                            {e.referral}
                                        </span>
                                    )}
                                </div>
                                <div className="-mt-2 grid gap-4 sm:grid-cols-2">
                                    <Field
                                        label="First name"
                                        error={e.first_name}
                                    >
                                        <Input
                                            className="h-11"
                                            autoComplete="given-name"
                                            value={form.data.first_name}
                                            onChange={(x) =>
                                                form.setData(
                                                    'first_name',
                                                    x.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </Field>
                                    <Field
                                        label="Last name"
                                        error={e.last_name}
                                    >
                                        <Input
                                            className="h-11"
                                            autoComplete="family-name"
                                            value={form.data.last_name}
                                            onChange={(x) =>
                                                form.setData(
                                                    'last_name',
                                                    x.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </Field>
                                    <Field label="Email" error={e.email}>
                                        <Input
                                            className="h-11"
                                            type="email"
                                            inputMode="email"
                                            autoComplete="email"
                                            value={form.data.email}
                                            onBlur={() => touch('email')}
                                            onChange={(x) =>
                                                form.setData(
                                                    'email',
                                                    x.target.value.trim(),
                                                )
                                            }
                                            aria-invalid={Boolean(e.email)}
                                            required
                                        />
                                    </Field>
                                    <Field
                                        label="Mobile phone"
                                        hint="US number, 10 digits"
                                        error={e.phone}
                                    >
                                        <PhoneInput
                                            className="h-11"
                                            value={phoneShown}
                                            onBlur={() => touch('phone')}
                                            onChange={(display, e164) => {
                                                setPhoneShown(display);
                                                form.setData(
                                                    'phone',
                                                    e164 ?? '',
                                                );
                                            }}
                                            placeholder="(512) 555-0100"
                                            aria-invalid={Boolean(e.phone)}
                                            required
                                        />
                                    </Field>
                                    <Field
                                        label="Password"
                                        hint="12 or more characters"
                                        error={e.password}
                                    >
                                        <PasswordInput
                                            className="h-11"
                                            autoComplete="new-password"
                                            value={form.data.password}
                                            onBlur={() => touch('password')}
                                            onChange={(x) =>
                                                form.setData(
                                                    'password',
                                                    x.target.value,
                                                )
                                            }
                                            aria-invalid={Boolean(e.password)}
                                            required
                                        />
                                    </Field>
                                    <Field
                                        label="Type the password again"
                                        error={e.password_confirmation}
                                    >
                                        <PasswordInput
                                            className="h-11"
                                            autoComplete="new-password"
                                            value={
                                                form.data.password_confirmation
                                            }
                                            onBlur={() =>
                                                touch('password_confirmation')
                                            }
                                            onChange={(x) =>
                                                form.setData(
                                                    'password_confirmation',
                                                    x.target.value,
                                                )
                                            }
                                            aria-invalid={Boolean(
                                                e.password_confirmation,
                                            )}
                                            required
                                        />
                                    </Field>
                                </div>

                                <Step n={2} title="Your licence" />
                                <div className="-mt-2 grid gap-4 sm:grid-cols-2">
                                    <Field
                                        label="State"
                                        error={e.licence_state}
                                    >
                                        <select
                                            className="h-11 w-full rounded-md border border-input bg-white px-3 text-base font-normal shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm"
                                            aria-invalid={Boolean(
                                                e.licence_state,
                                            )}
                                            value={form.data.licence_state}
                                            onChange={(x) =>
                                                form.setData(
                                                    'licence_state',
                                                    x.target.value,
                                                )
                                            }
                                            required
                                        >
                                            <option value="">Choose…</option>
                                            {Object.entries(states).map(
                                                ([c, n]) => (
                                                    <option key={c} value={c}>
                                                        {n}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </Field>
                                    <Field
                                        label="Licence number"
                                        error={e.licence_number}
                                    >
                                        <Input
                                            className="h-11"
                                            value={form.data.licence_number}
                                            onChange={(x) =>
                                                form.setData(
                                                    'licence_number',
                                                    x.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </Field>
                                    <p className="flex items-start gap-2 text-sm text-slate-500 sm:col-span-2">
                                        <ShieldCheck
                                            className="mt-0.5 size-4 shrink-0 text-emerald-600"
                                            aria-hidden="true"
                                        />
                                        We check your licence with the
                                        state&apos;s public records right away.
                                        Only active licences can join.
                                    </p>
                                </div>

                                {plans.length > 0 && (
                                    <>
                                        <Step
                                            n={3}
                                            title="How you want to be paid"
                                        />
                                        <fieldset className="-mt-2 grid gap-3">
                                            <legend className="sr-only">
                                                Your commission plan
                                            </legend>
                                            <div
                                                className={`grid gap-3 ${plans.length > 1 ? 'sm:grid-cols-2' : ''}`}
                                            >
                                                {plans.map((p) => {
                                                    const on =
                                                        form.data.plan ===
                                                        p.ulid;

                                                    return (
                                                        <label
                                                            key={p.ulid}
                                                            className={`grid cursor-pointer content-start gap-3 rounded-2xl border-2 p-5 transition-colors ${
                                                                on
                                                                    ? 'border-[#0E1B2C] bg-[#0E1B2C] text-white shadow-lg'
                                                                    : 'border-slate-200 hover:border-slate-400'
                                                            }`}
                                                        >
                                                            <span className="flex items-center gap-2.5 text-lg font-semibold">
                                                                <input
                                                                    type="radio"
                                                                    name="plan"
                                                                    className="size-5 accent-[#C8A96A]"
                                                                    checked={on}
                                                                    onChange={() =>
                                                                        form.setData(
                                                                            'plan',
                                                                            p.ulid,
                                                                        )
                                                                    }
                                                                />
                                                                {p.name}
                                                            </span>
                                                            {p.description && (
                                                                <span
                                                                    className={`text-[15px] ${on ? 'text-slate-200' : 'text-slate-600'}`}
                                                                >
                                                                    {
                                                                        p.description
                                                                    }
                                                                </span>
                                                            )}
                                                            <ul className="grid gap-2">
                                                                {p.lines.map(
                                                                    (l) => (
                                                                        <li
                                                                            key={
                                                                                l
                                                                            }
                                                                            className="flex gap-2 text-[15px] leading-snug"
                                                                        >
                                                                            <CircleCheck
                                                                                className={`mt-0.5 size-4 shrink-0 ${on ? 'text-[#C8A96A]' : 'text-emerald-600'}`}
                                                                                aria-hidden="true"
                                                                            />
                                                                            {l}
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                            <p className="text-sm text-slate-500">
                                                You can talk to {brokerage}{' '}
                                                about changing plans later.
                                            </p>
                                            {e.plan && (
                                                <span
                                                    role="alert"
                                                    className="text-sm text-destructive"
                                                >
                                                    {e.plan}
                                                </span>
                                            )}
                                        </fieldset>
                                    </>
                                )}

                                {packs.length > 0 && (
                                    <>
                                        <Step
                                            n={plans.length > 0 ? 4 : 3}
                                            title="Choose your pack"
                                        />
                                        <fieldset className="-mt-2 grid gap-3">
                                            <legend className="sr-only">
                                                Your pack
                                            </legend>
                                            <div className="grid gap-3 sm:grid-cols-3">
                                                {packs.map((p) => (
                                                    <label
                                                        key={p.ulid}
                                                        className={`grid cursor-pointer content-start gap-1 rounded-2xl border p-4 transition-colors ${
                                                            form.data.pack ===
                                                            p.ulid
                                                                ? 'border-[#0E1B2C] bg-[#0E1B2C] text-white'
                                                                : 'hover:border-slate-400'
                                                        }`}
                                                    >
                                                        <span className="flex items-center gap-2 font-semibold">
                                                            <input
                                                                type="radio"
                                                                name="pack"
                                                                className="size-4 accent-[#C8A96A]"
                                                                checked={
                                                                    form.data
                                                                        .pack ===
                                                                    p.ulid
                                                                }
                                                                onChange={() =>
                                                                    form.setData(
                                                                        'pack',
                                                                        p.ulid,
                                                                    )
                                                                }
                                                            />
                                                            {p.name}
                                                        </span>
                                                        <span className="text-2xl font-bold">
                                                            {usd(p.price_cents)}
                                                            <span className="text-sm font-normal opacity-70">
                                                                {' '}
                                                                / month
                                                            </span>
                                                        </span>
                                                        {p.description && (
                                                            <span className="text-sm opacity-80">
                                                                {p.description}
                                                            </span>
                                                        )}
                                                    </label>
                                                ))}
                                            </div>
                                            {e.pack && (
                                                <span
                                                    role="alert"
                                                    className="text-sm text-destructive"
                                                >
                                                    {e.pack}
                                                </span>
                                            )}
                                        </fieldset>
                                    </>
                                )}

                                <label className="flex items-start gap-3 text-[15px] text-slate-700">
                                    <input
                                        type="checkbox"
                                        className="mt-1 size-4"
                                        checked={form.data.agree}
                                        onChange={(x) =>
                                            form.setData(
                                                'agree',
                                                x.target.checked,
                                            )
                                        }
                                    />
                                    <span>
                                        I agree to {brokerage}&apos;s
                                        independent contractor terms and
                                        RightAlly&apos;s terms of service.
                                        {e.agree && (
                                            <span
                                                role="alert"
                                                className="block text-sm text-destructive"
                                            >
                                                {e.agree}
                                            </span>
                                        )}
                                    </span>
                                </label>
                                <Button
                                    type="submit"
                                    size="lg"
                                    className="h-13 bg-[#0E1B2C] text-base text-white hover:bg-[#1A2C45]"
                                    disabled={form.processing || !ready}
                                >
                                    {form.processing
                                        ? 'Checking your licence…'
                                        : packs.length > 0
                                          ? 'Check my licence and continue to payment'
                                          : 'Check my licence and join'}
                                </Button>
                            </form>
                        )}
                    </div>
                </section>
            </main>
        </>
    );
}
