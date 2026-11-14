import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    settings: {
        configured: boolean;
        sid_last4: string | null;
        sms_from: string | null;
        voice_from: string | null;
        sms_enabled: boolean;
        voice_enabled: boolean;
    };
    tenants: { ulid: string; name: string; sms: boolean; voice: boolean }[];
};

/** HQ: the Twilio account and numbers for texts and automated calls, and which brokerages may use them. */
export default function HqMessaging({ settings, tenants }: Props) {
    const form = useForm({
        sid: '',
        token: '',
        sms_from: settings.sms_from ?? '',
        voice_from: settings.voice_from ?? '',
        sms_enabled: settings.sms_enabled,
        voice_enabled: settings.voice_enabled,
    });
    const opts = { preserveScroll: true };

    return (
        <>
            <Head title="Texts and calls" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Texts and calls"
                    description="The Twilio account RightAlly uses for text messages and automated voice calls (deadline alerts, signing codes), and which brokerages may use them."
                />
                <form
                    className="grid gap-3 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/hq/messaging', {
                            ...opts,
                            onSuccess: () => form.reset('sid', 'token'),
                        });
                    }}
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-semibold">Twilio account</h2>
                        {settings.configured ? (
                            <Badge variant="secondary">
                                Connected · SID …{settings.sid_last4}
                            </Badge>
                        ) : (
                            <Badge variant="outline">Not connected</Badge>
                        )}
                    </div>
                    <div className="grid gap-2 md:grid-cols-2">
                        <Input
                            aria-label="Account SID"
                            placeholder={
                                settings.configured
                                    ? 'Account SID (leave blank to keep)'
                                    : 'Account SID (AC…)'
                            }
                            value={form.data.sid}
                            onChange={(e) =>
                                form.setData('sid', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Auth token"
                            type="password"
                            autoComplete="off"
                            placeholder="Auth token (stored encrypted)"
                            value={form.data.token}
                            onChange={(e) =>
                                form.setData('token', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Text from number"
                            placeholder="Texts from (+15125550100)"
                            value={form.data.sms_from}
                            onChange={(e) =>
                                form.setData('sms_from', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Call from number"
                            placeholder="Calls from (+15125550101)"
                            value={form.data.voice_from}
                            onChange={(e) =>
                                form.setData('voice_from', e.target.value)
                            }
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Text numbers need A2P 10DLC registration in Twilio
                        before messages are delivered in the US.
                    </p>
                    <div className="flex flex-wrap gap-4">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={form.data.sms_enabled}
                                onChange={(e) =>
                                    form.setData(
                                        'sms_enabled',
                                        e.target.checked,
                                    )
                                }
                            />
                            Texts on
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={form.data.voice_enabled}
                                onChange={(e) =>
                                    form.setData(
                                        'voice_enabled',
                                        e.target.checked,
                                    )
                                }
                            />
                            Automated calls on
                        </label>
                    </div>
                    <Button
                        size="sm"
                        className="justify-self-start"
                        disabled={form.processing}
                    >
                        Save
                    </Button>
                    {Object.values(form.errors)[0] && (
                        <p role="alert" className="text-destructive">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                </form>

                <section className="grid gap-2 text-sm">
                    <h2 className="font-semibold">Brokerages</h2>
                    <p className="text-muted-foreground">
                        Which brokerages may send deadline alerts by text and
                        automated call. Agents still opt in themselves.
                    </p>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[420px]">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="p-2">Brokerage</th>
                                    <th className="p-2">Texts</th>
                                    <th className="p-2">Calls</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {tenants.map((t) => (
                                    <tr key={t.ulid}>
                                        <td className="p-2">{t.name}</td>
                                        {(['sms', 'voice'] as const).map(
                                            (k) => (
                                                <td key={k} className="p-2">
                                                    <input
                                                        type="checkbox"
                                                        className="size-4"
                                                        aria-label={`${t.name} ${k === 'sms' ? 'texts' : 'calls'}`}
                                                        checked={t[k]}
                                                        onChange={(e) =>
                                                            router.post(
                                                                `/hq/messaging/tenants/${t.ulid}`,
                                                                {
                                                                    sms:
                                                                        k ===
                                                                        'sms'
                                                                            ? e
                                                                                  .target
                                                                                  .checked
                                                                            : t.sms,
                                                                    voice:
                                                                        k ===
                                                                        'voice'
                                                                            ? e
                                                                                  .target
                                                                                  .checked
                                                                            : t.voice,
                                                                },
                                                                opts,
                                                            )
                                                        }
                                                    />
                                                </td>
                                            ),
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}
