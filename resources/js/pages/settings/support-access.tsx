import { Head, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    policy: 'open' | 'ask';
    open_until: string | null;
    hours: number;
    waiting: { ulid: string; by: string; reason: string; at: string }[];
    visits: { by: string; viewed: string; reason: string; at: string }[];
};

/**
 * FL1 (specs/core/16): whether RightAlly support may view the brokerage's account to help without asking first, the
 * requests waiting for an answer, and every support visit (who, when, whose account, why).
 */
export default function SupportAccess({
    policy,
    open_until,
    hours,
    waiting,
    visits,
}: Props) {
    const opts = { preserveScroll: true };
    const openNow = open_until !== null && new Date(open_until) > new Date();

    return (
        <>
            <Head title="Support access" />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Support access"
                    description="Decide whether RightAlly support can look at your account to help you. Every visit is listed below and the person whose account was viewed is emailed."
                />
                <fieldset className="grid gap-3 rounded-lg border p-4 text-base">
                    <legend className="px-1 font-semibold">
                        When support needs to look
                    </legend>
                    {(
                        [
                            [
                                'open',
                                'Support may help without asking',
                                'Fastest help. Visits last at most 60 minutes and cannot change passwords, payouts or bank details.',
                            ],
                            [
                                'ask',
                                'Support must ask me first',
                                `You get an email; support can help for ${hours} hours after you allow it.`,
                            ],
                        ] as const
                    ).map(([value, label, hint]) => (
                        <label key={value} className="flex items-start gap-2">
                            <input
                                type="radio"
                                name="policy"
                                className="mt-1 size-5"
                                checked={policy === value}
                                onChange={() =>
                                    router.post(
                                        '/settings/support-access',
                                        { policy: value },
                                        opts,
                                    )
                                }
                            />
                            <span>
                                {label}
                                <span className="block text-sm text-muted-foreground">
                                    {hint}
                                </span>
                            </span>
                        </label>
                    ))}
                    {policy === 'ask' && openNow && (
                        <div className="flex flex-wrap items-center gap-3">
                            <p>
                                Support may help until{' '}
                                {new Date(open_until).toLocaleString()}.
                            </p>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        '/settings/support-access/close',
                                        {},
                                        opts,
                                    )
                                }
                            >
                                End it now
                            </Button>
                        </div>
                    )}
                </fieldset>

                {waiting.length > 0 && (
                    <section className="grid gap-3 text-base">
                        <h2 className="text-lg font-semibold">
                            Support is asking
                        </h2>
                        {waiting.map((r) => (
                            <div
                                key={r.ulid}
                                className="grid gap-2 rounded-lg border p-4"
                            >
                                <p>
                                    <strong>{r.by}</strong> asks to help with:{' '}
                                    {r.reason}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {new Date(r.at).toLocaleString()}
                                </p>
                                <div className="flex gap-2">
                                    <Button
                                        onClick={() =>
                                            router.post(
                                                `/settings/support-access/${r.ulid}`,
                                                { allow: true },
                                                opts,
                                            )
                                        }
                                    >
                                        Allow for {hours} hours
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/settings/support-access/${r.ulid}`,
                                                { allow: false },
                                                opts,
                                            )
                                        }
                                    >
                                        Decline
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </section>
                )}

                <section className="grid gap-2 text-base">
                    <h2 className="text-lg font-semibold">Support visits</h2>
                    {visits.length === 0 ? (
                        <p className="text-muted-foreground">
                            Support has not viewed your account.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {visits.map((v, i) => (
                                <li key={i} className="grid gap-1 p-3">
                                    <span>
                                        <strong>{v.by}</strong> viewed{' '}
                                        {v.viewed}'s account
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {new Date(v.at).toLocaleString()} ·{' '}
                                        {v.reason}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
