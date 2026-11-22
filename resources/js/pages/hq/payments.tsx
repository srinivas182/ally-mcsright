import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, XCircle } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { CopyText, OutLink, SetupGuide } from '@/components/setup-guide';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    settings: {
        mode: string;
        agent_pays_default: boolean;
        automatic_tax: boolean;
        test_set: boolean;
        live_set: boolean;
        card_fee_bps: number;
        card_fee_cents: number;
    };
    webhookUrl: string;
    events: Record<string, string>;
    check: { step: string; ok: boolean; message: string }[] | null;
};

function Keys({ mode, set }: { mode: 'test' | 'live'; set: boolean }) {
    const form = useForm({
        mode,
        publishable: '',
        secret: '',
        webhook_secret: '',
    });

    return (
        <form
            className="grid gap-2 rounded-lg border p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/hq/payments/keys', {
                    preserveScroll: true,
                    onSuccess: () =>
                        form.reset('publishable', 'secret', 'webhook_secret'),
                });
            }}
        >
            <span className="flex items-center gap-2 font-medium capitalize">
                {mode} keys{' '}
                {set ? (
                    <Badge variant="secondary">Saved</Badge>
                ) : (
                    <Badge variant="outline">Not set</Badge>
                )}
            </span>
            <Input
                aria-label={`${mode} publishable key`}
                placeholder={`pk_${mode}_…`}
                autoComplete="off"
                value={form.data.publishable}
                onChange={(e) => form.setData('publishable', e.target.value)}
            />
            <Input
                aria-label={`${mode} secret key`}
                placeholder={`sk_${mode}_…`}
                type="password"
                autoComplete="off"
                value={form.data.secret}
                onChange={(e) => form.setData('secret', e.target.value)}
            />
            <Input
                aria-label={`${mode} webhook secret`}
                placeholder="whsec_…"
                type="password"
                autoComplete="off"
                value={form.data.webhook_secret}
                onChange={(e) => form.setData('webhook_secret', e.target.value)}
            />
            {Object.values(form.errors).length > 0 && (
                <p role="alert" className="text-destructive">
                    {Object.values(form.errors)[0]}
                </p>
            )}
            <Button
                type="submit"
                variant="outline"
                className="justify-self-start"
                disabled={form.processing}
            >
                {set ? 'Replace keys' : 'Save keys'}
            </Button>
            <p className="text-xs text-muted-foreground">
                Keys are encrypted and never shown again. Saving needs your
                password.
            </p>
        </form>
    );
}

/** HQ payment settings: Stripe keys, test or live, agents-pay default, Stripe Tax. */
export default function Payments({
    settings,
    webhookUrl,
    events,
    check,
}: Props) {
    const [checking, setChecking] = useState(false);
    const passed = (step: string) =>
        check?.find((c) => c.step === step)?.ok ?? undefined;
    const stripe =
        settings.mode === 'live'
            ? 'https://dashboard.stripe.com'
            : 'https://dashboard.stripe.com/test';

    return (
        <>
            <Head title="Payments" />
            <div className="flex max-w-3xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Payments"
                    description="Stripe for the whole platform. Brokerages never enter keys; they connect Stripe only to receive payouts."
                />
                <section className="flex flex-wrap items-center gap-3 rounded-lg border p-4 text-sm">
                    Mode:{' '}
                    <Badge
                        variant={
                            settings.mode === 'live' ? 'default' : 'secondary'
                        }
                        className="uppercase"
                    >
                        {settings.mode}
                    </Badge>
                    {settings.mode === 'test' ? (
                        <Button
                            size="sm"
                            disabled={!settings.live_set}
                            onClick={() =>
                                router.post(
                                    '/hq/payments/mode',
                                    { mode: 'live' },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Go live
                        </Button>
                    ) : (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    '/hq/payments/mode',
                                    { mode: 'test' },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Switch to test
                        </Button>
                    )}
                    <span className="text-muted-foreground">
                        Webhook address for Stripe: <code>{webhookUrl}</code>
                    </span>
                </section>
                <SetupGuide
                    title="How to connect Stripe"
                    intro="About 10 minutes. Start in Test mode: no real money moves, and you can try a sign-up with Stripe's test card 4242 4242 4242 4242. Do the same steps again in Live mode when you are ready."
                    steps={[
                        {
                            title: 'Open your Stripe account',
                            body: (
                                <p>
                                    Sign in at{' '}
                                    <OutLink href="https://dashboard.stripe.com">
                                        dashboard.stripe.com
                                    </OutLink>{' '}
                                    (or create a free account). Turn on{' '}
                                    <strong>Test mode</strong> with the switch
                                    at the top of Stripe.
                                </p>
                            ),
                        },

                        {
                            title: 'Add the webhook in Stripe',
                            done:
                                passed('webhook') === undefined
                                    ? undefined
                                    : Boolean(
                                          passed('webhook') && passed('events'),
                                      ),
                            body: (
                                <>
                                    <p>
                                        A webhook is how Stripe tells RightAlly
                                        that someone paid. In Stripe, open{' '}
                                        <OutLink href={`${stripe}/webhooks`}>
                                            Developers › Webhooks
                                        </OutLink>{' '}
                                        and click <strong>Add endpoint</strong>.
                                    </p>
                                    <p>Endpoint URL: paste this address</p>
                                    <CopyText
                                        value={webhookUrl}
                                        label="webhook address"
                                    />
                                    <p>
                                        Click <strong>Select events</strong> and
                                        tick these {Object.keys(events).length}{' '}
                                        (type each name in Stripe's search box):
                                    </p>
                                    <ul className="grid gap-1">
                                        {Object.entries(events).map(
                                            ([name, why]) => (
                                                <li
                                                    key={name}
                                                    className="grid gap-0.5 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-center sm:gap-3"
                                                >
                                                    <CopyText value={name} />
                                                    <span className="text-xs">
                                                        {why}
                                                    </span>
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                    <p>
                                        Click <strong>Add endpoint</strong>.
                                    </p>
                                </>
                            ),
                        },
                        {
                            title: 'Copy three values into RightAlly and save',
                            done: passed('secret'),
                            body: (
                                <>
                                    <p>
                                        In Stripe, on the new webhook's page,
                                        click <strong>Reveal</strong> under{' '}
                                        <strong>Signing secret</strong> (starts
                                        whsec_). Then open{' '}
                                        <OutLink href={`${stripe}/apikeys`}>
                                            Developers › API keys
                                        </OutLink>{' '}
                                        for the <strong>Publishable key</strong>{' '}
                                        (starts pk_test_) and the{' '}
                                        <strong>Secret key</strong> (starts
                                        sk_test_; click Reveal).
                                    </p>
                                    <p>
                                        Paste all three in the{' '}
                                        <strong>test</strong> box below and
                                        click Save keys. RightAlly asks for your
                                        password, keeps them locked away, and
                                        never shows them again.
                                    </p>
                                </>
                            ),
                        },

                        {
                            title: 'Check that it works',
                            done: passed('received'),
                            body: (
                                <>
                                    <p>
                                        In Stripe, on the webhook's page, click{' '}
                                        <strong>Send test event</strong>. Then
                                        click Check my setup. Each line turns
                                        green when that part is right, or says
                                        what to fix.
                                    </p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        className="justify-self-start"
                                        disabled={checking}
                                        onClick={() =>
                                            router.post(
                                                '/hq/payments/check',
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    onStart: () =>
                                                        setChecking(true),
                                                    onFinish: () =>
                                                        setChecking(false),
                                                },
                                            )
                                        }
                                    >
                                        {checking
                                            ? 'Checking…'
                                            : 'Check my setup'}
                                    </Button>
                                    {check && (
                                        <ul
                                            className="grid gap-1"
                                            aria-live="polite"
                                        >
                                            {check.map((c) => (
                                                <li
                                                    key={c.step}
                                                    className="flex items-start gap-2 text-foreground"
                                                >
                                                    {c.ok ? (
                                                        <CheckCircle2
                                                            aria-label="OK"
                                                            className="mt-0.5 size-4 shrink-0 text-emerald-600"
                                                        />
                                                    ) : (
                                                        <XCircle
                                                            aria-label="To fix"
                                                            className="mt-0.5 size-4 shrink-0 text-destructive"
                                                        />
                                                    )}
                                                    {c.message}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </>
                            ),
                        },
                        {
                            title: 'Go live when you are ready',
                            body: (
                                <p>
                                    In Stripe, turn Test mode off and repeat
                                    steps 2 to 4 with your live keys (pk_live_,
                                    sk_live_) and a new live webhook, saved in
                                    the <strong>live</strong> box. Then click Go
                                    live above.
                                </p>
                            ),
                        },
                    ]}
                />
                <div className="grid gap-3 md:grid-cols-2">
                    <Keys mode="test" set={settings.test_set} />
                    <Keys mode="live" set={settings.live_set} />
                </div>
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={settings.agent_pays_default}
                            onChange={(e) =>
                                router.post(
                                    '/hq/payments/options',
                                    {
                                        agent_pays_default: e.target.checked,
                                        automatic_tax: settings.automatic_tax,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        />
                        Agents pay RightAlly directly for every brokerage
                        (brokers carry no liability). Exceptions are set per
                        brokerage.
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={settings.automatic_tax}
                            onChange={(e) =>
                                router.post(
                                    '/hq/payments/options',
                                    {
                                        agent_pays_default:
                                            settings.agent_pays_default,
                                        automatic_tax: e.target.checked,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        />
                        Calculate sales tax automatically (Stripe Tax)
                    </label>
                </section>
                <CardFee
                    bps={settings.card_fee_bps}
                    cents={settings.card_fee_cents}
                />
            </div>
        </>
    );
}

/**
 * The card processing fee added when a paid app is paid by card instead of credits (specs/connect/14), so
 * RightAlly keeps the same amount either way. Stripe's standard US card fee by default.
 */
function CardFee({ bps, cents }: { bps: number; cents: number }) {
    const form = useForm({
        percent: String(bps / 100),
        fixed: (cents / 100).toFixed(2),
    });

    return (
        <form
            className="grid gap-2 rounded-lg border p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/hq/payments/card-fee', { preserveScroll: true });
            }}
        >
            <p className="font-medium">Card processing fee on paid apps</p>
            <p className="text-muted-foreground">
                Added to an app add-on's price when it's paid by card instead of
                RightAlly credits. US card-surcharge rules apply: keep it at or
                below your real card cost, and it's shown before anyone pays.
            </p>
            <div className="flex flex-wrap items-end gap-3">
                <label className="grid gap-1">
                    Percent
                    <Input
                        className="w-28"
                        inputMode="decimal"
                        value={form.data.percent}
                        onChange={(e) =>
                            form.setData('percent', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1">
                    Plus, in dollars
                    <Input
                        className="w-28"
                        inputMode="decimal"
                        value={form.data.fixed}
                        onChange={(e) => form.setData('fixed', e.target.value)}
                    />
                </label>
                <Button variant="outline" disabled={form.processing}>
                    Save
                </Button>
            </div>
            {Object.values(form.errors).map((m) => (
                <p key={m} className="text-destructive">
                    {m}
                </p>
            ))}
        </form>
    );
}

Payments.layout = {
    breadcrumbs: [{ title: 'Payments', href: '/hq/payments' }],
};
