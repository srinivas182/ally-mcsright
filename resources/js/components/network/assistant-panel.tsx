import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usd } from '@/lib/cents';

import { AgentCard } from './agent-card';
import type { NetworkCard } from './types';

export type AssistantState = {
    criteria: {
        side: string | null;
        area: string | null;
        price_band: string | null;
        timing: string | null;
        language: string | null;
        needs: string | null;
        unclear: string[];
    };
    found: {
        network: NetworkCard[];
        local: {
            key: string;
            name: string;
            office: string | null;
            sides: number;
            volume_cents: number;
            ours: boolean;
        }[];
    };
    intros: Record<string, string>;
} | null;

/**
 * AR3 (specs/network/03): say what the client needs in your own words; the assistant finds network agents there and
 * the top local agents from your MLS data, and drafts a first message you send yourself.
 */
export function AssistantPanel({
    state,
    onSend,
}: {
    state: AssistantState;
    onSend: (area: string, side: string | null) => void;
}) {
    const form = useForm({ words: '' });
    const [busy, setBusy] = useState<string | null>(null);
    const [copied, setCopied] = useState<string | null>(null);
    const c = state?.criteria;

    return (
        <div className="grid gap-5 text-base">
            <form
                className="grid gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/network/assistant');
                }}
            >
                <label className="grid gap-1">
                    Tell me about your client: where they're going, buying or
                    selling, and anything that matters. Leave out their name and
                    contact details.
                    <textarea
                        className="min-h-24 rounded-md border bg-transparent p-2"
                        maxLength={2000}
                        value={form.data.words}
                        placeholder="My clients are moving to Denver this spring and want to buy around $550k. They'd like an agent who speaks Spanish."
                        onChange={(e) => form.setData('words', e.target.value)}
                    />
                </label>
                <InputError message={form.errors.words} />
                <div>
                    <Button type="submit" size="lg" disabled={form.processing}>
                        {form.processing ? 'Looking…' : 'Find agents for me'}
                    </Button>
                </div>
            </form>

            {c && (
                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="text-lg font-semibold">What I understood</h2>
                    <p>
                        {c.side === 'seller'
                            ? 'Selling'
                            : c.side === 'buyer'
                              ? 'Buying'
                              : 'Buying or selling'}{' '}
                        in {c.area ?? "an area you didn't name"}
                        {[
                            c.price_band,
                            c.timing,
                            c.language && `speaks ${c.language}`,
                        ]
                            .filter(Boolean)
                            .map((x) => ` · ${x}`)
                            .join('')}
                    </p>
                    {c.unclear.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            I couldn't use: {c.unclear.join('; ')}
                        </p>
                    )}
                </section>
            )}

            {state && c?.area && (
                <>
                    <section className="grid gap-3">
                        <h2 className="text-lg font-semibold">
                            RightAlly agents who cover {c.area}
                        </h2>
                        {state.found.network.length === 0 && (
                            <p className="text-muted-foreground">
                                None yet in the referral network.
                            </p>
                        )}
                        <ul className="grid gap-3">
                            {state.found.network.map((card) => (
                                <AgentCard key={card.ulid} card={card}>
                                    <Button
                                        size="lg"
                                        onClick={() =>
                                            onSend(c.area ?? '', c.side)
                                        }
                                    >
                                        Send a referral
                                    </Button>
                                </AgentCard>
                            ))}
                        </ul>
                    </section>
                    <section className="grid gap-3">
                        <h2 className="text-lg font-semibold">
                            Top local agents{' '}
                            {c.side === 'seller'
                                ? 'for sellers'
                                : c.side === 'buyer'
                                  ? 'for buyers'
                                  : ''}{' '}
                            in {c.area} (last 12 months)
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            From your MLS data. RightAlly doesn't contact them:
                            copy the message and send it yourself.
                        </p>
                        {state.found.local.length === 0 && (
                            <p className="text-muted-foreground">
                                No closings there in your MLS data.
                            </p>
                        )}
                        <ul className="grid gap-3">
                            {state.found.local.map((a) => (
                                <li
                                    key={a.key}
                                    className="grid gap-2 rounded-lg border p-4"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <strong className="text-lg">
                                            {a.name}
                                        </strong>
                                        {a.office && (
                                            <span className="text-muted-foreground">
                                                {a.office}
                                            </span>
                                        )}
                                        {a.ours && (
                                            <Badge variant="secondary">
                                                Your brokerage
                                            </Badge>
                                        )}
                                    </div>
                                    <p>
                                        {a.sides} closing
                                        {a.sides === 1 ? '' : 's'} there ·{' '}
                                        {usd(a.volume_cents)}
                                    </p>
                                    {state.intros[a.key] ? (
                                        <div className="grid gap-2">
                                            <p className="rounded-md bg-muted/40 p-3 whitespace-pre-line">
                                                {state.intros[a.key]}
                                            </p>
                                            <div>
                                                <Button
                                                    variant="outline"
                                                    onClick={() => {
                                                        void navigator.clipboard?.writeText(
                                                            state.intros[a.key],
                                                        );
                                                        setCopied(a.key);
                                                    }}
                                                >
                                                    {copied === a.key
                                                        ? 'Copied'
                                                        : 'Copy message'}
                                                </Button>
                                            </div>
                                        </div>
                                    ) : (
                                        <div>
                                            <Button
                                                variant="outline"
                                                disabled={busy === a.key}
                                                onClick={() => {
                                                    setBusy(a.key);
                                                    router.post(
                                                        '/network/assistant/intro',
                                                        { key: a.key },
                                                        {
                                                            preserveScroll: true,
                                                            onFinish: () =>
                                                                setBusy(null),
                                                        },
                                                    );
                                                }}
                                            >
                                                {busy === a.key
                                                    ? 'Writing…'
                                                    : 'Draft a first message'}
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                </>
            )}
        </div>
    );
}
