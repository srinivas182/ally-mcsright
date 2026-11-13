import { router, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type IdentitySettingsData = {
    id_policy: string;
    owner_check: boolean;
    below_value_percent: number;
    provider: string;
    provider_ready: boolean;
};

const policies: [string, string][] = [
    ['risk', 'When there are fraud signals (recommended)'],
    ['sellers', 'Every seller'],
    ['everyone', 'Every signer'],
    ['off', 'Off'],
];

/**
 * Brokerage settings for identity checks (government ID + live selfie) and the forms that always need them.
 */
export function IdentitySettings({
    settings,
    templates,
}: {
    settings: IdentitySettingsData;
    templates: { ulid: string; name: string; high_risk: boolean }[];
}) {
    const form = useForm({
        id_policy: settings.id_policy,
        owner_check: settings.owner_check,
        below_value_percent: settings.below_value_percent,
    });

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <div>
                <h2 className="font-semibold">Identity checks</h2>
                <p className="text-muted-foreground">
                    Signers prove who they are with a government ID and a live
                    selfie through {settings.provider}. RightAlly keeps only the
                    result, never the ID images. A check is reused for 12
                    months.
                </p>
                {!settings.provider_ready && (
                    <p
                        role="status"
                        className="mt-1 rounded border border-amber-300 bg-amber-50 p-2 text-amber-900"
                    >
                        Not set up yet: connect the brokerage's Stripe account
                        under Billing to turn identity checks on.
                    </p>
                )}
            </div>
            <label className="grid gap-1">
                <span className="text-xs text-muted-foreground">
                    Who must verify
                </span>
                <select
                    aria-label="Who must verify"
                    className="justify-self-start rounded border bg-background px-2 py-1.5"
                    value={form.data.id_policy}
                    onChange={(e) => form.setData('id_policy', e.target.value)}
                >
                    {policies.map(([v, l]) => (
                        <option key={v} value={v}>
                            {l}
                        </option>
                    ))}
                </select>
            </label>
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.owner_check}
                    onChange={(e) =>
                        form.setData('owner_check', e.target.checked)
                    }
                />
                Check sellers against the owner of record on listings
            </label>
            <label className="flex flex-wrap items-center gap-2">
                Flag a price below
                <Input
                    aria-label="Percent of estimated value"
                    type="number"
                    min={10}
                    max={100}
                    className="w-20"
                    value={form.data.below_value_percent}
                    onChange={(e) =>
                        form.setData(
                            'below_value_percent',
                            Number(e.target.value),
                        )
                    }
                />
                % of the estimated value
            </label>
            <Button
                size="sm"
                className="justify-self-start"
                disabled={form.processing}
                onClick={() =>
                    form.post('/forms/identity-settings', {
                        preserveScroll: true,
                    })
                }
            >
                Save identity settings
            </Button>
            {Object.values(form.errors)[0] && (
                <p role="alert" className="text-destructive">
                    {Object.values(form.errors)[0]}
                </p>
            )}
            {templates.length > 0 && (
                <div className="grid gap-1 border-t pt-2">
                    <p className="text-xs text-muted-foreground">
                        High-risk forms (deeds, powers of attorney): everyone
                        signing them verifies, whatever the policy.
                    </p>
                    {templates.map((t) => (
                        <label key={t.ulid} className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={t.high_risk}
                                onChange={(e) =>
                                    router.post(
                                        `/forms/templates/${t.ulid}/risk`,
                                        { high_risk: e.target.checked },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                            {t.name}
                        </label>
                    ))}
                </div>
            )}
        </section>
    );
}
