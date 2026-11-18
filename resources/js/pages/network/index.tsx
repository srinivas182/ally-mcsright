import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { AgentCard, TrustDetails } from '@/components/network/agent-card';
import {
    AssistantPanel,
    type AssistantState,
} from '@/components/network/assistant-panel';
import { ProfileForm, type MyProfile } from '@/components/network/profile-form';
import { ReferralItem } from '@/components/network/referral-item';
import { SendForm } from '@/components/network/send-form';
import type { NetworkCard, NetworkReferral } from '@/components/network/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    tab: 'received' | 'sent' | 'find' | 'profile' | 'assistant';
    brokerage: { enabled: boolean; default_fee_percent: string };
    profile: (NetworkCard & MyProfile) | null;
    referrals: { received: NetworkReferral[]; sent: NetworkReferral[] };
    search: { area: string; side: string | null; language: string };
    results: NetworkCard[];
    deals: { ulid: string; label: string }[];
    sides: Record<string, string>;
    isOwner: boolean;
    assistant: AssistantState;
};

const TABS = [
    ['received', 'Sent to me'],
    ['sent', 'I sent'],
    ['assistant', 'Ask the assistant'],
    ['find', 'Find an agent'],
    ['profile', 'My profile'],
] as const;

/** AR1 (specs/network/01): the agent referral network between RightAlly brokerages. */
export default function Network({
    tab,
    brokerage,
    profile,
    referrals,
    search,
    results,
    deals,
    sides,
    isOwner,
    assistant,
}: Props) {
    const [area, setArea] = useState(search.area);
    const [side, setSide] = useState(search.side ?? '');
    const [language, setLanguage] = useState(search.language);
    const [sendTo, setSendTo] = useState<string | null>(null);
    const waiting = referrals.received.filter(
        (r) => r.status === 'sent',
    ).length;

    return (
        <>
            <Head title="Referral network" />
            <div className="flex max-w-4xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Referral network"
                    description="Send clients who are moving to trusted agents at other RightAlly brokerages, and get referrals in return. RightAlly takes no share of referral fees."
                />
                {!brokerage.enabled ? (
                    <p className="rounded-lg border p-4 text-base">
                        Your brokerage hasn't switched on the referral network
                        yet.{' '}
                        {isOwner ? (
                            <Link
                                className="underline"
                                href="/settings/network"
                            >
                                Switch it on in Settings › Referral network.
                            </Link>
                        ) : (
                            'Ask your broker to switch it on.'
                        )}
                    </p>
                ) : (
                    <>
                        <nav
                            aria-label="Referral network"
                            className="flex flex-wrap gap-2"
                        >
                            {TABS.map(([key, label]) => (
                                <Button
                                    key={key}
                                    size="lg"
                                    variant={
                                        tab === key ? 'default' : 'outline'
                                    }
                                    aria-current={
                                        tab === key ? 'page' : undefined
                                    }
                                    asChild
                                >
                                    <Link href={`/network?tab=${key}`}>
                                        {label}
                                        {key === 'received' && waiting > 0
                                            ? ` (${waiting} waiting)`
                                            : ''}
                                    </Link>
                                </Button>
                            ))}
                        </nav>

                        {profile === null && tab !== 'profile' && (
                            <p className="rounded-lg border p-4 text-base">
                                Set up your profile so other agents can find you
                                and you can send referrals.{' '}
                                <Link
                                    className="underline"
                                    href="/network?tab=profile"
                                >
                                    Set up my profile
                                </Link>
                            </p>
                        )}

                        {tab === 'profile' && profile !== null && (
                            <section className="grid gap-2 rounded-lg border p-4 text-base">
                                <h2 className="text-lg font-semibold">
                                    Your Referral Trust Score:{' '}
                                    {profile.trust.score ?? 'not yet'}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Worked out only from how you handle
                                    referrals: answering, updates, closing,
                                    paying fees on time and ratings.
                                </p>
                                {profile.trust.score !== null && (
                                    <TrustDetails parts={profile.trust.parts} />
                                )}
                                {profile.tips.length > 0 && (
                                    <ul className="list-disc pl-5">
                                        {profile.tips.map((t) => (
                                            <li key={t}>{t}</li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        )}
                        {tab === 'profile' && (
                            <ProfileForm profile={profile} sides={sides} />
                        )}

                        {tab === 'assistant' && (
                            <AssistantPanel
                                state={assistant}
                                onSend={(a, sd) =>
                                    router.get('/network', {
                                        tab: 'find',
                                        area: a,
                                        side: sd ?? '',
                                    })
                                }
                            />
                        )}

                        {(tab === 'received' || tab === 'sent') && (
                            <ul className="grid gap-3">
                                {referrals[tab].length === 0 && (
                                    <li className="text-base text-muted-foreground">
                                        {tab === 'received'
                                            ? 'No referrals sent to you yet.'
                                            : 'You haven\'t sent any referrals yet. Use "Find an agent".'}
                                    </li>
                                )}
                                {referrals[tab].map((r) => (
                                    <ReferralItem
                                        key={r.ulid}
                                        r={r}
                                        received={tab === 'received'}
                                        deals={deals}
                                    />
                                ))}
                            </ul>
                        )}

                        {tab === 'find' && (
                            <>
                                <form
                                    className="flex flex-wrap items-end gap-3 text-base"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        router.get(
                                            '/network',
                                            {
                                                tab: 'find',
                                                area,
                                                side,
                                                language,
                                            },
                                            { preserveState: true },
                                        );
                                    }}
                                >
                                    <label className="grid gap-1">
                                        City or ZIP
                                        <Input
                                            value={area}
                                            placeholder="Denver, CO"
                                            onChange={(e) =>
                                                setArea(e.target.value)
                                            }
                                            required
                                        />
                                    </label>
                                    <label className="grid gap-1">
                                        The client is
                                        <select
                                            className="min-h-11 rounded-md border bg-transparent px-2"
                                            value={side}
                                            onChange={(e) =>
                                                setSide(e.target.value)
                                            }
                                        >
                                            <option value="">Either</option>
                                            <option value="buyer">
                                                Buying
                                            </option>
                                            <option value="seller">
                                                Selling
                                            </option>
                                        </select>
                                    </label>
                                    <label className="grid gap-1">
                                        Language
                                        <Input
                                            value={language}
                                            onChange={(e) =>
                                                setLanguage(e.target.value)
                                            }
                                        />
                                    </label>
                                    <Button type="submit" size="lg">
                                        Find agents
                                    </Button>
                                </form>
                                {search.area !== '' && results.length === 0 && (
                                    <p className="text-base text-muted-foreground">
                                        No network agents cover {search.area}{' '}
                                        yet.
                                    </p>
                                )}
                                <ul className="grid gap-3">
                                    {results.map((card) => (
                                        <AgentCard key={card.ulid} card={card}>
                                            {profile !== null &&
                                                sendTo !== card.ulid && (
                                                    <Button
                                                        size="lg"
                                                        onClick={() =>
                                                            setSendTo(card.ulid)
                                                        }
                                                    >
                                                        Send a referral
                                                    </Button>
                                                )}
                                            {sendTo === card.ulid && (
                                                <SendForm
                                                    to={card}
                                                    area={search.area}
                                                    side={search.side}
                                                    fee={
                                                        brokerage.default_fee_percent
                                                    }
                                                    onDone={() =>
                                                        setSendTo(null)
                                                    }
                                                />
                                            )}
                                        </AgentCard>
                                    ))}
                                </ul>
                            </>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
