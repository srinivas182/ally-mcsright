import { Head, router, useForm } from '@inertiajs/react';

import { CopyText, OutLink, SetupGuide } from '@/components/setup-guide';
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
    tenants: {
        ulid: string;
        name: string;
        sms: boolean;
        voice: boolean;
        number: string | null;
    }[];
    webhook: string;
};

/** HQ: the Twilio account and numbers for texts and automated calls, and which brokerages may use them. */
export default function HqMessaging({ settings, tenants, webhook }: Props) {
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
                <SetupGuide
                    title="How to connect Twilio"
                    intro="Twilio sends RightAlly's texts and automated calls. Allow a few days: US carriers must approve your texting registration first."
                    steps={[
                        {
                            title: 'Open a Twilio account',
                            done: settings.configured,
                            body: (
                                <p>
                                    Sign up or sign in at{' '}
                                    <OutLink href="https://console.twilio.com">
                                        console.twilio.com
                                    </OutLink>{' '}
                                    and add a payment card (Upgrade, so texts
                                    reach any number, not only verified ones).
                                </p>
                            ),
                        },
                        {
                            title: 'Register for US texting (A2P 10DLC)',
                            body: (
                                <p>
                                    In Twilio, open Messaging, then Regulatory
                                    Compliance, and register your business
                                    (brand) and how you text (campaign: account
                                    notifications and opted-in contacts). US
                                    carriers block unregistered texts. Approval
                                    usually takes a few days.
                                </p>
                            ),
                        },
                        {
                            title: 'Buy a phone number',
                            done: Boolean(settings.sms_from),
                            body: (
                                <p>
                                    In Twilio, open Phone Numbers, then Buy a
                                    number. Pick a local number that can send
                                    texts and make calls, and add it to your
                                    registered campaign.
                                </p>
                            ),
                        },
                        {
                            title: 'Copy your account details into RightAlly',
                            done: settings.configured,
                            body: (
                                <p>
                                    On the Twilio Console home page, under
                                    Account Info, copy the{' '}
                                    <strong>Account SID</strong> and{' '}
                                    <strong>Auth Token</strong>. Paste them
                                    below with your number (+1…) as the text and
                                    call number, and save.
                                </p>
                            ),
                        },
                        {
                            title: 'Send replies to RightAlly',
                            body: (
                                <>
                                    <p>
                                        In Twilio, open your number and set{' '}
                                        <strong>A message comes in</strong> to
                                        this address (Webhook, HTTP POST), so
                                        replies and STOP requests reach
                                        RightAlly:
                                    </p>
                                    <CopyText
                                        value={webhook}
                                        label="incoming message address"
                                    />
                                </>
                            ),
                        },
                        {
                            title: 'Switch on texts and calls, then choose brokerages',
                            done:
                                settings.sms_enabled || settings.voice_enabled,
                            body: (
                                <p>
                                    Tick texts and calls below, then switch them
                                    on for each brokerage that should use them.
                                    Texts only go to people who agreed, between
                                    8am and 9pm their time.
                                </p>
                            ),
                        },
                    ]}
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
                        Which brokerages may send texts and automated calls, and
                        each brokerage's own texting number (leave empty to use
                        the platform number). Agents still opt in themselves. In
                        Twilio, set each number's incoming message webhook to{' '}
                        <code className="break-all">{webhook}</code>.
                    </p>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[420px]">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="p-2">Brokerage</th>
                                    <th className="p-2">Texts</th>
                                    <th className="p-2">Calls</th>
                                    <th className="p-2">Own number</th>
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
                                        <td className="p-2">
                                            <input
                                                aria-label={`${t.name} texting number`}
                                                placeholder="+15125550100"
                                                defaultValue={t.number ?? ''}
                                                className="w-36 rounded border bg-background px-2 py-1"
                                                onBlur={(e) =>
                                                    e.target.value !==
                                                        (t.number ?? '') &&
                                                    router.post(
                                                        `/hq/messaging/tenants/${t.ulid}`,
                                                        {
                                                            sms: t.sms,
                                                            voice: t.voice,
                                                            number: e.target
                                                                .value,
                                                        },
                                                        opts,
                                                    )
                                                }
                                            />
                                        </td>
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
