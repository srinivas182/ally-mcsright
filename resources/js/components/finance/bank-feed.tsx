import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { postJson } from '@/lib/http';

export type Feed = {
    available: boolean;
    provider: 'plaid' | 'mx' | 'teller' | null;
    mode: string | null;
    status: 'choose' | 'active' | 'error' | null;
    account: string | null;
    last_synced_at: string | null;
    error: string | null;
    accounts: { id: string; name: string; mask: string | null }[];
};

type Start = {
    provider: 'plaid' | 'mx' | 'teller';
    link_token?: string;
    widget_url?: string;
    user_guid?: string;
    application_id?: string;
    environment?: string;
};

declare global {
    interface Window {
        Plaid?: {
            create: (o: {
                token: string;
                onSuccess: (publicToken: string) => void;
            }) => { open: () => void };
        };
        TellerConnect?: {
            setup: (o: {
                applicationId: string;
                environment: string;
                products: string[];
                onSuccess: (e: { accessToken: string }) => void;
            }) => { open: () => void };
        };
    }
}

const SCRIPTS = {
    plaid: 'https://cdn.plaid.com/link/v2/stable/link-initialize.js',
    teller: 'https://cdn.teller.io/connect/connect.js',
};

const NAMES = { plaid: 'Plaid', mx: 'MX', teller: 'Teller' };

function load(src: string): Promise<void> {
    return new Promise((resolve, reject) => {
        if (document.querySelector(`script[src="${src}"]`)) {
            resolve();
            return;
        }
        const s = document.createElement('script');
        s.src = src;
        s.onload = () => resolve();
        s.onerror = () => reject(new Error('The bank sign-in could not load.'));
        document.head.appendChild(s);
    });
}

/**
 * BK1 (specs/finance/20): connect the operating account through the provider HQ chose (read-only), pick the account,
 * and fill a month's reconciliation from the feed.
 */
export function BankFeedPanel({
    feed,
    month,
    locked,
}: {
    feed: Feed;
    month: string;
    locked: boolean;
}) {
    const [error, setError] = useState<string | null>(null);
    const [mx, setMx] = useState<{ url: string; guid: string } | null>(null);
    const [ending, setEnding] = useState('');
    const finish = (data: Record<string, string>) =>
        router.post('/finance/bank/feed/finish', data, {
            preserveScroll: true,
        });

    const connect = async () => {
        setError(null);
        try {
            const s = await postJson<Start>('/finance/bank/feed/start', {});
            if (s.provider === 'plaid' && s.link_token) {
                await load(SCRIPTS.plaid);
                window.Plaid?.create({
                    token: s.link_token,
                    onSuccess: (publicToken) =>
                        finish({ public_token: publicToken }),
                }).open();
            } else if (s.provider === 'teller' && s.application_id) {
                await load(SCRIPTS.teller);
                window.TellerConnect?.setup({
                    applicationId: s.application_id,
                    environment: s.environment ?? 'sandbox',
                    products: ['transactions', 'balance'],
                    onSuccess: (e) => finish({ access_token: e.accessToken }),
                }).open();
            } else if (s.provider === 'mx' && s.widget_url && s.user_guid) {
                setMx({ url: s.widget_url, guid: s.user_guid });
            }
        } catch (e) {
            setError(
                e instanceof Error ? e.message : 'The bank sign-in failed.',
            );
        }
    };

    if (!feed.available && feed.status === null) {
        return null;
    }

    return (
        <section className="grid gap-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="grow text-lg font-semibold">Live bank feed</h2>
                {feed.provider && (
                    <Badge variant="outline">
                        {NAMES[feed.provider]}
                        {feed.mode === 'test' ? ' (test)' : ''}
                    </Badge>
                )}
            </div>
            <p className="text-sm text-muted-foreground">
                Read-only: transactions come in every morning so you don't have
                to download statements. Money can never move through it. You
                sign in to your bank in{' '}
                {feed.provider ? NAMES[feed.provider] : 'the provider'}
                's window, not here.
            </p>
            {feed.status === null && (
                <Button
                    className="justify-self-start"
                    onClick={() => void connect()}
                >
                    Connect the operating account
                </Button>
            )}
            {mx && (
                <div className="grid gap-2">
                    <iframe
                        src={mx.url}
                        title="MX bank sign-in"
                        className="h-[600px] w-full max-w-lg rounded-md border"
                    />
                    <Button
                        className="justify-self-start"
                        onClick={() => finish({ user_guid: mx.guid })}
                    >
                        I have signed in to my bank
                    </Button>
                </div>
            )}
            {feed.status === 'choose' && (
                <div className="grid gap-2">
                    <p>Which account is the operating account?</p>
                    {feed.accounts.map((a) => (
                        <Button
                            key={a.id}
                            variant="outline"
                            className="justify-self-start"
                            onClick={() =>
                                router.post(
                                    '/finance/bank/feed/account',
                                    { account: a.id },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {a.name}
                            {a.mask ? ` ····${a.mask}` : ''}
                        </Button>
                    ))}
                </div>
            )}
            {(feed.status === 'active' || feed.status === 'error') && (
                <div className="grid gap-3">
                    <p>
                        <strong>{feed.account}</strong>
                        {feed.last_synced_at &&
                            ` · last brought in ${new Date(feed.last_synced_at).toLocaleString()}`}
                    </p>
                    {feed.error && (
                        <p role="alert" className="text-destructive">
                            {feed.error}
                        </p>
                    )}
                    {!locked && (
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.post('/finance/bank/from-feed', {
                                    month,
                                    ending_balance: ending,
                                });
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Ending balance on the statement (optional; we
                                work it out from the feed)
                                <Input
                                    inputMode="decimal"
                                    value={ending}
                                    onChange={(e) => setEnding(e.target.value)}
                                    placeholder="25,000.00"
                                />
                            </label>
                            <Button>Fill {month} from the feed</Button>
                        </form>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    '/finance/bank/feed/sync',
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Refresh now
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    '/finance/bank/feed/disconnect',
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Disconnect
                        </Button>
                    </div>
                </div>
            )}
            {error && (
                <p role="alert" className="text-destructive">
                    {error}
                </p>
            )}
        </section>
    );
}
