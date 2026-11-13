import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    rails: Record<
        string,
        { enabled: boolean; mode: string; keys_set: boolean }
    >;
    webhooks: Record<string, string>;
    instantLimits: { max_flat_cents: number; max_percent: string };
};

const names: Record<string, string> = {
    dwolla: 'Dwolla',
    modern_treasury: 'Modern Treasury',
    stripe: 'Stripe Treasury',
};
const notes: Record<string, string> = {
    dwolla: "Brokerages verify their business and link their own bank under RightAlly's Dwolla platform. Money moves brokerage bank → agent bank.",
    modern_treasury:
        'Brokerages connect their own Modern Treasury organisation. No platform keys needed here.',
    stripe: "Pays from each brokerage's own Stripe Treasury financial account. Uses the Stripe keys in Payments; add the Treasury webhook secret here.",
};

function Keys({ rail }: { rail: 'dwolla' | 'stripe' }) {
    const form = useForm({
        rail,
        mode: 'test',
        key: '',
        secret: '',
        webhook_secret: '',
    });

    return (
        <form
            className="flex flex-wrap gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/hq/payout-rails/keys', {
                    preserveScroll: true,
                    onSuccess: () =>
                        form.reset('key', 'secret', 'webhook_secret'),
                });
            }}
        >
            <select
                aria-label="Mode"
                className="rounded border bg-background px-2 py-1.5"
                value={form.data.mode}
                onChange={(e) => form.setData('mode', e.target.value)}
            >
                <option value="test">Test</option>
                <option value="live">Live</option>
            </select>
            {rail === 'dwolla' && (
                <Input
                    aria-label="Dwolla key"
                    placeholder="Key"
                    className="w-48"
                    autoComplete="off"
                    value={form.data.key}
                    onChange={(e) => form.setData('key', e.target.value)}
                />
            )}
            {rail === 'dwolla' && (
                <Input
                    aria-label="Dwolla secret"
                    placeholder="Secret"
                    type="password"
                    className="w-48"
                    autoComplete="off"
                    value={form.data.secret}
                    onChange={(e) => form.setData('secret', e.target.value)}
                />
            )}
            <Input
                aria-label="Webhook secret"
                placeholder="Webhook secret"
                type="password"
                className="w-48"
                autoComplete="off"
                value={form.data.webhook_secret}
                onChange={(e) => form.setData('webhook_secret', e.target.value)}
            />
            <Button type="submit" size="sm" variant="outline">
                Save keys
            </Button>
        </form>
    );
}

/** HQ: which payout rails brokerages can use, and RightAlly's platform credentials. */
export default function PayoutRails({ rails, webhooks, instantLimits }: Props) {
    const limits = useForm({
        max_flat: String(instantLimits.max_flat_cents / 100),
        max_percent: instantLimits.max_percent,
    });
    return (
        <>
            <Head title="Payout rails" />
            <div className="flex max-w-3xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Payout rails"
                    description="How brokerages can pay their agents automatically. Brokerages pay from their own bank; RightAlly never holds the money. The bank file is always available."
                />
                {Object.entries(rails).map(([key, r]) => (
                    <section
                        key={key}
                        className="grid gap-2 rounded-lg border p-4 text-sm"
                    >
                        <span className="flex items-center gap-2 font-semibold">
                            {names[key]}{' '}
                            {r.enabled ? (
                                <Badge>On</Badge>
                            ) : (
                                <Badge variant="outline">Off</Badge>
                            )}{' '}
                            {key !== 'modern_treasury' && (
                                <Badge
                                    variant="secondary"
                                    className="uppercase"
                                >
                                    {r.mode}
                                    {r.keys_set ? '' : ' · keys needed'}
                                </Badge>
                            )}
                        </span>
                        <p className="text-muted-foreground">{notes[key]}</p>
                        {webhooks[key] && (
                            <p className="text-muted-foreground">
                                Webhook address: <code>{webhooks[key]}</code>
                            </p>
                        )}
                        {(key === 'dwolla' || key === 'stripe') && (
                            <Keys rail={key} />
                        )}
                        <Button
                            size="sm"
                            variant={r.enabled ? 'outline' : 'default'}
                            className="justify-self-start"
                            onClick={() =>
                                router.post(
                                    '/hq/payout-rails/enable',
                                    { rail: key, enabled: !r.enabled },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {r.enabled ? 'Turn off' : 'Turn on for brokerages'}
                        </Button>
                    </section>
                ))}
                <form
                    className="flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        limits.post('/hq/payout-rails/instant-limits', {
                            preserveScroll: true,
                        });
                    }}
                >
                    Get paid now: brokerages may charge up to $
                    <Input
                        aria-label="Maximum flat fee ($)"
                        type="number"
                        min={0}
                        step="0.01"
                        className="w-24"
                        value={limits.data.max_flat}
                        onChange={(e) =>
                            limits.setData('max_flat', e.target.value)
                        }
                    />
                    plus up to
                    <Input
                        aria-label="Maximum percent"
                        type="number"
                        min={0}
                        max={10}
                        step="0.01"
                        className="w-20"
                        value={limits.data.max_percent}
                        onChange={(e) =>
                            limits.setData('max_percent', e.target.value)
                        }
                    />
                    %
                    <Button type="submit" size="sm" variant="outline">
                        Save limits
                    </Button>
                </form>
            </div>
        </>
    );
}

PayoutRails.layout = {
    breadcrumbs: [{ title: 'Payout rails', href: '/hq/payout-rails' }],
};
