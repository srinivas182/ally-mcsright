import { Head, router, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/** Open-house sign-in kiosk (LI2): big, simple, one visitor at a time, on the agent's tablet. */
export default function Kiosk({
    listing,
    openHouse,
    consent,
}: {
    listing: { ulid: string; street: string };
    openHouse: { ulid: string; ended: boolean; visitors: number };
    consent: string;
}) {
    const base = `/list-iq/${listing.ulid}/open-houses/${openHouse.ulid}`;
    const form = useForm({
        name: '',
        email: '',
        phone: '',
        agent: 'no',
        sms_consent: false,
    });

    return (
        <>
            <Head title={`Welcome · ${listing.street}`} />
            <div className="mx-auto grid max-w-xl gap-6 p-6 text-lg">
                <h1 className="text-center text-3xl font-semibold">
                    Welcome to {listing.street}
                </h1>
                {openHouse.ended ? (
                    <p className="text-center">
                        This open house has ended. Thank you!
                    </p>
                ) : (
                    <form
                        className="grid gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`${base}/sign-in`, {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <label className="grid gap-1">
                            Your name
                            <Input
                                className="h-14 text-lg"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                required
                                autoComplete="name"
                            />
                        </label>
                        <label className="grid gap-1">
                            Email
                            <Input
                                className="h-14 text-lg"
                                type="email"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                                autoComplete="email"
                            />
                        </label>
                        <label className="grid gap-1">
                            Mobile phone
                            <Input
                                className="h-14 text-lg"
                                type="tel"
                                value={form.data.phone}
                                onChange={(e) =>
                                    form.setData('phone', e.target.value)
                                }
                                autoComplete="tel"
                            />
                        </label>
                        <fieldset className="grid gap-2">
                            <legend>
                                Are you working with a real estate agent?
                            </legend>
                            <div className="flex flex-wrap gap-2">
                                {(
                                    [
                                        ['yes', 'Yes'],
                                        ['no', 'No'],
                                        ['looking', 'Not yet'],
                                    ] as const
                                ).map(([k, label]) => (
                                    <Button
                                        key={k}
                                        type="button"
                                        size="lg"
                                        variant={
                                            form.data.agent === k
                                                ? 'default'
                                                : 'outline'
                                        }
                                        aria-pressed={form.data.agent === k}
                                        onClick={() => form.setData('agent', k)}
                                    >
                                        {label}
                                    </Button>
                                ))}
                            </div>
                        </fieldset>
                        <label className="flex items-start gap-3 text-base">
                            <input
                                type="checkbox"
                                className="mt-1 size-6"
                                checked={form.data.sms_consent}
                                onChange={(e) =>
                                    form.setData(
                                        'sms_consent',
                                        e.target.checked,
                                    )
                                }
                            />
                            <span>{consent}</span>
                        </label>
                        {Object.values(form.errors).map((m) => (
                            <p
                                key={m}
                                role="alert"
                                className="text-base text-red-700"
                            >
                                {m}
                            </p>
                        ))}
                        <Button
                            size="lg"
                            className="h-14 text-lg"
                            disabled={form.processing}
                        >
                            Sign in
                        </Button>
                    </form>
                )}
                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>{openHouse.visitors} signed in</span>
                    {!openHouse.ended && (
                        <button
                            type="button"
                            className="underline"
                            onClick={() => router.post(`${base}/end`)}
                        >
                            End the open house (agent)
                        </button>
                    )}
                </div>
            </div>
        </>
    );
}
