import { useState } from 'react';

export type CallCard = {
    key: string;
    they_say: string;
    you_say: string;
    tip: string;
};

/** LN4 (E20): tap what the person just said to see a short, honest way to answer. */
export function CallCards({ cards }: { cards: CallCard[] }) {
    const [open, setOpen] = useState<string | null>(null);
    const card = cards.find((c) => c.key === open) ?? null;

    return (
        <section
            className="grid gap-2 rounded-lg border p-4"
            aria-labelledby="call-cards"
        >
            <h3 id="call-cards" className="font-medium">
                If they say…
            </h3>
            <div className="flex flex-wrap gap-2">
                {cards.map((c) => (
                    <button
                        key={c.key}
                        type="button"
                        aria-pressed={open === c.key}
                        className={`rounded-full border px-3 py-1.5 text-sm ${open === c.key ? 'bg-primary text-primary-foreground' : 'bg-background'}`}
                        onClick={() => setOpen(open === c.key ? null : c.key)}
                    >
                        {c.they_say}
                    </button>
                ))}
            </div>
            {card && (
                <div
                    className="grid gap-1 rounded-md bg-muted/50 p-3"
                    aria-live="polite"
                >
                    <p className="text-lg">{card.you_say}</p>
                    <p className="text-sm text-muted-foreground">{card.tip}</p>
                </div>
            )}
        </section>
    );
}
