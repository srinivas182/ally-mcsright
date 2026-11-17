import { Badge } from '@/components/ui/badge';

import type { NetworkCard } from './types';

/** One network agent, in plain words: where, which side, languages, Trust Score and (if shared) a call button. */
export function AgentCard({
    card,
    children,
}: {
    card: NetworkCard;
    children?: React.ReactNode;
}) {
    return (
        <li className="flex flex-col gap-2 rounded-lg border p-4 text-base">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="text-lg">{card.name}</strong>
                <span className="text-muted-foreground">{card.brokerage}</span>
                {card.verified && (
                    <Badge variant="secondary">Licence checked</Badge>
                )}
                {card.trust.score !== null ? (
                    <Badge>Trust Score {card.trust.score}</Badge>
                ) : (
                    <Badge variant="outline">New to the network</Badge>
                )}
            </div>
            <p>
                {card.sides} in {card.areas.join(', ')}
            </p>
            <p className="text-sm text-muted-foreground">
                Speaks {card.languages.join(', ')}
                {card.trust.count > 0 &&
                    ` · ${card.trust.count} referral${card.trust.count === 1 ? '' : 's'} received`}
            </p>
            {card.bio && <p className="text-sm">{card.bio}</p>}
            {card.trust.score !== null && (
                <TrustDetails parts={card.trust.parts} />
            )}
            <div className="flex flex-wrap gap-2">
                {card.phone && (
                    <a
                        className="inline-flex min-h-11 items-center rounded-md border px-4 underline-offset-4 hover:underline"
                        href={`tel:${card.phone}`}
                    >
                        Call {card.name.split(' ')[0]}
                    </a>
                )}
                {children}
            </div>
        </li>
    );
}

/** What the Trust Score is made of, in plain words. */
export function TrustDetails({
    parts,
}: {
    parts: NetworkCard['trust']['parts'];
}) {
    return (
        <details className="text-sm">
            <summary className="cursor-pointer underline-offset-4 hover:underline">
                What makes up this score
            </summary>
            <ul className="mt-1 grid gap-1">
                {Object.entries(parts).map(([key, p]) => (
                    <li key={key}>
                        <strong>{p.label}</strong> ({p.score} of 100):{' '}
                        {p.detail}
                    </li>
                ))}
            </ul>
        </details>
    );
}
