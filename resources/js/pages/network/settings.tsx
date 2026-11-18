import { Head, router, useForm } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    brokerage: { enabled: boolean; default_fee_percent: string };
    profiles: {
        ulid: string;
        name: string;
        licence: string | null;
        state: string;
        verified: boolean;
        status: string;
    }[];
};

/** AR1: the owner switches the referral network on, sets the usual fee, and checks agents' licences. */
export default function NetworkSettings({ brokerage, profiles }: Props) {
    const form = useForm({
        enabled: brokerage.enabled,
        default_fee_percent: brokerage.default_fee_percent,
    });

    return (
        <>
            <Head title="Referral network settings" />
            <div className="flex max-w-3xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Referral network"
                    description="Let your agents send and receive referrals with agents at other RightAlly brokerages. Fees are paid brokerage to brokerage; RightAlly takes no share."
                />
                <form
                    className="grid gap-4 rounded-lg border p-4 text-base"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/settings/network', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.enabled}
                            onChange={(e) =>
                                form.setData('enabled', e.target.checked)
                            }
                        />
                        Use the referral network
                    </label>
                    <label className="grid max-w-xs gap-1">
                        Usual referral fee (%)
                        <Input
                            inputMode="decimal"
                            value={form.data.default_fee_percent}
                            onChange={(e) =>
                                form.setData(
                                    'default_fee_percent',
                                    e.target.value,
                                )
                            }
                        />
                    </label>
                    <InputError message={form.errors.default_fee_percent} />
                    <div>
                        <Button
                            type="submit"
                            size="lg"
                            disabled={form.processing}
                        >
                            Save
                        </Button>
                    </div>
                </form>
                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">
                        Your agents in the network
                    </h2>
                    {profiles.length === 0 && (
                        <p className="text-base text-muted-foreground">
                            No agents have set up a profile yet.
                        </p>
                    )}
                    <ul className="grid gap-2">
                        {profiles.map((p) => (
                            <li
                                key={p.ulid}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-base"
                            >
                                <span>
                                    <strong>{p.name}</strong>{' '}
                                    {p.licence
                                        ? `· ${p.state} licence ${p.licence}`
                                        : '· no licence number yet'}
                                    {p.status === 'paused' && ' · paused'}
                                </span>
                                {p.licence && (
                                    <span className="flex items-center gap-2">
                                        {p.verified && (
                                            <Badge variant="secondary">
                                                Checked
                                            </Badge>
                                        )}
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/settings/network/${p.ulid}/verify`,
                                                    { checked: !p.verified },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {p.verified
                                                ? 'Undo licence check'
                                                : 'I checked this licence'}
                                        </Button>
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
