import { Link, router } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { usd } from '@/lib/cents';

export type MlsBoardData = {
    found: {
        ulid: string;
        street: string;
        city: string | null;
        price_cents: number;
        mls_number: string;
        status: string;
        agent: string;
        mls: string;
    }[];
    prompts: { deal: string; street: string; say: string; action: string }[];
    connected: boolean;
    auto: boolean;
};

/**
 * ML2: on My listings. Listings found in the MLS under the agents' MLS IDs (one click to add), deals whose MLS status
 * moved on ("Mark it under contract?"), and, for the owner, adding them by themselves and the sales check.
 */
export function FromTheMls({
    data,
    isOwner,
    reviewer,
}: {
    data: MlsBoardData;
    isOwner: boolean;
    reviewer: boolean;
}) {
    if (!data.connected) {
        return null;
    }

    return (
        <div className="grid gap-4">
            {data.prompts.length > 0 && (
                <section aria-labelledby="mls-moved" className="grid gap-2">
                    <h2 id="mls-moved" className="sr-only">
                        The MLS changed
                    </h2>
                    {data.prompts.map((p) => (
                        <div
                            key={p.deal}
                            role="status"
                            className="flex flex-wrap items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
                        >
                            <span className="font-medium">{p.street}:</span>
                            <span>{p.say}</span>
                            <Button size="sm" className="ml-auto" asChild>
                                <Link href={`/deals/${p.deal}`}>
                                    {p.action === 'closed'
                                        ? 'Mark it closed'
                                        : 'Mark it under contract'}
                                </Link>
                            </Button>
                        </div>
                    ))}
                </section>
            )}

            {data.found.length > 0 && (
                <section
                    aria-labelledby="mls-found"
                    className="grid gap-3 rounded-xl border border-primary/30 bg-primary/5 p-4"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <Building2
                            aria-hidden="true"
                            className="size-5 text-primary"
                        />
                        <h2 id="mls-found" className="text-lg font-semibold">
                            Found in the MLS
                        </h2>
                        <span className="text-muted-foreground">
                            {data.found.length === 1
                                ? '1 listing is'
                                : `${data.found.length} listings are`}{' '}
                            in the MLS but not on this board yet.
                        </span>
                    </div>
                    <ul className="grid gap-2 md:grid-cols-2">
                        {data.found.map((l) => (
                            <li
                                key={l.ulid}
                                className="flex flex-wrap items-center gap-3 rounded-lg border bg-background p-3"
                            >
                                <span className="grid flex-1 gap-0.5">
                                    <span className="font-medium">
                                        {l.street}
                                        {l.city ? `, ${l.city}` : ''}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {usd(l.price_cents)} · MLS{' '}
                                        {l.mls_number}
                                        {reviewer && ` · ${l.agent}`}
                                    </span>
                                </span>
                                {l.status !== 'Active' && (
                                    <Badge variant="outline">{l.status}</Badge>
                                )}
                                <span className="flex gap-1">
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/deals/listings/mls/${l.ulid}/add`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Plus aria-hidden="true" /> Add it
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.post(
                                                `/deals/listings/mls/${l.ulid}/dismiss`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Not now
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {(isOwner || reviewer) && (
                <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    {isOwner && (
                        <label className="flex items-center gap-2">
                            <Switch
                                checked={data.auto}
                                onCheckedChange={(on) =>
                                    router.post(
                                        '/deals/listings/mls-auto',
                                        { on },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                            Add agents' new MLS listings by themselves every
                            night
                        </label>
                    )}
                    {reviewer && (
                        <Link
                            href="/deals/mls-check"
                            className="underline underline-offset-2"
                        >
                            Sales in the MLS that aren't in RightAlly
                        </Link>
                    )}
                </div>
            )}
        </div>
    );
}
