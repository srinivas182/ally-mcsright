import { Head, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usd } from '@/lib/cents';

type Props = {
    brokerage: string;
    sponsor: string | null;
    code: string | null;
    unknownCode: boolean;
    packs: {
        ulid: string;
        name: string;
        description: string | null;
        price_cents: number;
    }[];
    states: Record<string, string>;
    cancelled: boolean;
};

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <label className="grid gap-1.5 text-[15px] font-medium">
            {label}
            {children}
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

/**
 * CM4: join a brokerage through an agent's link. A valid licence and the pack payment place the new agent
 * straight away, under the agent who shared the link.
 */
export default function Join({
    brokerage,
    sponsor,
    code,
    unknownCode,
    packs,
    states,
    cancelled,
}: Props) {
    const form = useForm({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
        licence_state: '',
        licence_number: '',
        pack: packs[0]?.ulid ?? '',
        agree: false,
    });
    const e = form.errors as Record<string, string | undefined>;

    return (
        <>
            <Head title={`Join ${brokerage}`}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main className="min-h-screen bg-muted/40 px-4 py-10">
                <div className="mx-auto grid max-w-2xl gap-6">
                    <header className="grid gap-2 text-center">
                        <p className="text-sm font-semibold tracking-wide text-primary">
                            {brokerage}
                        </p>
                        <h1 className="text-3xl font-bold">Join {brokerage}</h1>
                        <p className="text-muted-foreground">
                            {sponsor
                                ? `You were invited by ${sponsor}. They become your sponsor when you join.`
                                : 'Fill this in, check your licence and choose your pack. You can start straight away.'}
                        </p>
                        {unknownCode && (
                            <p role="status" className="text-sm text-amber-700">
                                We could not find the agent whose link you used.
                                You can still join; ask them to send the link
                                again if you want them as your sponsor.
                            </p>
                        )}
                        {cancelled && (
                            <p role="status" className="text-sm text-amber-700">
                                The payment was not finished. Fill in the form
                                again to continue.
                            </p>
                        )}
                    </header>
                    <form
                        className="grid gap-5 rounded-2xl border bg-card p-6 shadow-sm"
                        onSubmit={(ev) => {
                            ev.preventDefault();
                            form.post(code ? `/join/${code}` : '/join');
                        }}
                    >
                        <h2 className="text-lg font-semibold">About you</h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="First name" error={e.first_name}>
                                <Input
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
                            <Field label="Last name" error={e.last_name}>
                                <Input
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
                                    type="email"
                                    autoComplete="email"
                                    value={form.data.email}
                                    onChange={(x) =>
                                        form.setData('email', x.target.value)
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Mobile phone" error={e.phone}>
                                <Input
                                    type="tel"
                                    inputMode="tel"
                                    autoComplete="tel"
                                    value={form.data.phone}
                                    onChange={(x) =>
                                        form.setData('phone', x.target.value)
                                    }
                                    required
                                />
                            </Field>
                            <Field
                                label="Choose a password (12 or more characters)"
                                error={e.password}
                            >
                                <Input
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.password}
                                    onChange={(x) =>
                                        form.setData('password', x.target.value)
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Type it again">
                                <Input
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.password_confirmation}
                                    onChange={(x) =>
                                        form.setData(
                                            'password_confirmation',
                                            x.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                        </div>

                        <h2 className="text-lg font-semibold">Your licence</h2>
                        <p className="-mt-3 text-sm text-muted-foreground">
                            We check it with the state&apos;s public records
                            now. Only active licences can join.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="State" error={e.licence_state}>
                                <select
                                    className="h-10 rounded-md border bg-background px-3 font-normal"
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
                                    {Object.entries(states).map(([c, n]) => (
                                        <option key={c} value={c}>
                                            {n}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field
                                label="Licence number"
                                error={e.licence_number}
                            >
                                <Input
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
                        </div>

                        {packs.length > 0 && (
                            <fieldset className="grid gap-3">
                                <legend className="mb-2 text-lg font-semibold">
                                    Choose your pack
                                </legend>
                                <div className="grid gap-3 sm:grid-cols-3">
                                    {packs.map((p) => (
                                        <label
                                            key={p.ulid}
                                            className={`grid cursor-pointer gap-1 rounded-xl border p-4 ${
                                                form.data.pack === p.ulid
                                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                                    : ''
                                            }`}
                                        >
                                            <span className="flex items-center gap-2 font-semibold">
                                                <input
                                                    type="radio"
                                                    name="pack"
                                                    className="size-4"
                                                    checked={
                                                        form.data.pack ===
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
                                            <span className="text-xl font-bold">
                                                {usd(p.price_cents)}
                                                <span className="text-sm font-normal text-muted-foreground">
                                                    {' '}
                                                    / month
                                                </span>
                                            </span>
                                            {p.description && (
                                                <span className="text-sm text-muted-foreground">
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
                        )}

                        <label className="flex items-start gap-2 text-[15px]">
                            <input
                                type="checkbox"
                                className="mt-1 size-4"
                                checked={form.data.agree}
                                onChange={(x) =>
                                    form.setData('agree', x.target.checked)
                                }
                            />
                            <span>
                                I agree to {brokerage}&apos;s independent
                                contractor terms and RightAlly&apos;s terms of
                                service.
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
                            className="h-12 text-base"
                            disabled={form.processing}
                        >
                            {form.processing
                                ? 'Checking your licence…'
                                : packs.length > 0
                                  ? 'Check my licence and pay'
                                  : 'Check my licence and join'}
                        </Button>
                    </form>
                </div>
            </main>
        </>
    );
}
