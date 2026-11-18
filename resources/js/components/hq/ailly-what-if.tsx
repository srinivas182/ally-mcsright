import { useState } from 'react';

import { Input } from '@/components/ui/input';
import { usd } from '@/lib/cents';

type Props = {
    /** What one AIlly reply cost on average in the period shown (cents), from real use; null when there is none yet. */
    replyCents: number | null;
};

const n = (v: string, fallback: number): number => {
    const x = Number(v.replace(/[^0-9.]/g, ''));
    return Number.isFinite(x) && v.trim() !== '' ? x : fallback;
};

/**
 * TR1 (A31): the AIlly what-if calculator. Change the number of brokerages, conversations, replies and the package
 * price and allowance, and see what AIlly would cost RightAlly, per brokerage and as a share of the price, and how
 * many conversations go beyond the allowance (paid in credits).
 */
export function AillyWhatIf({ replyCents }: Props) {
    const [v, setV] = useState({
        brokerages: '100',
        conversations: '300',
        replies: '6',
        reply: replyCents !== null ? (replyCents / 100).toFixed(4) : '0.0040',
        price: '499',
        allowance: '250',
        credit: '0.10',
    });
    const brokerages = n(v.brokerages, 0);
    const conversations = n(v.conversations, 0);
    const replies = n(v.replies, 0);
    const replyCost = n(v.reply, 0) * 100;
    const price = n(v.price, 0) * 100;
    const allowance = n(v.allowance, 0);
    const credit = n(v.credit, 0) * 100;

    const perConversation = replies * replyCost;
    const perBrokerage = conversations * perConversation;
    const total = perBrokerage * brokerages;
    const over = Math.max(0, conversations - allowance);
    const overIncome = over * credit;
    const share = price > 0 ? (100 * perBrokerage) / price : 0;

    const field = (key: keyof typeof v, label: string, hint?: string) => (
        <label className="grid gap-1 text-sm">
            <span className="font-medium">{label}</span>
            <Input
                inputMode="decimal"
                value={v[key]}
                onChange={(e) => setV({ ...v, [key]: e.target.value })}
            />
            {hint && (
                <span className="text-xs text-muted-foreground">{hint}</span>
            )}
        </label>
    );

    return (
        <section
            aria-labelledby="what-if"
            className="grid gap-4 rounded-lg border p-4"
        >
            <div>
                <h2 id="what-if" className="text-lg font-semibold">
                    AIlly what-if calculator
                </h2>
                <p className="text-sm text-muted-foreground">
                    Try numbers before changing a package. The reply cost starts
                    from real use in the period above.
                </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {field('brokerages', 'Brokerages')}
                {field('conversations', 'Conversations a month, each')}
                {field('replies', 'Replies per conversation')}
                {field(
                    'reply',
                    'Cost of one reply ($)',
                    replyCents === null
                        ? 'No AIlly use yet: an estimate'
                        : 'From real use',
                )}
                {field('price', 'Package price a month ($)')}
                {field('allowance', 'Conversations included')}
                {field('credit', 'Credit charged per extra conversation ($)')}
            </div>
            <dl
                className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                aria-live="polite"
            >
                {[
                    [
                        'One conversation costs',
                        usd(Math.round(perConversation)),
                    ],
                    ['Per brokerage a month', usd(Math.round(perBrokerage))],
                    ['All brokerages a month', usd(Math.round(total))],
                    ['Share of the package price', `${share.toFixed(1)}%`],
                    ['Extra conversations each', over.toLocaleString()],
                    ['Credits from extras, each', usd(Math.round(overIncome))],
                ].map(([label, value]) => (
                    <div key={label} className="rounded-md bg-muted/50 p-3">
                        <dt className="text-xs text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="text-lg font-semibold">{value}</dd>
                    </div>
                ))}
            </dl>
            {share > 20 && (
                <p
                    role="status"
                    className="text-sm text-amber-700 dark:text-amber-400"
                >
                    AIlly would use more than a fifth of the package price.
                    Consider a smaller allowance, reused answers or a lighter
                    model.
                </p>
            )}
        </section>
    );
}
