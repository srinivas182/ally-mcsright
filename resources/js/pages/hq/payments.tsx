import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
export default function Payments({ settings, webhookUrl }: Props) {
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
