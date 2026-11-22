import { Check } from 'lucide-react';

import { Badge } from '@/components/ui/badge';

/** One website design, with what its small preview needs. */
export type DesignCard = {
    key: string;
    name: string;
    description: string;
    theme?: string;
    primary?: string;
    accent?: string;
    font?: string;
    luxury?: boolean;
    /** A demo of the design with made-up content, in a new tab. */
    preview?: string;
};

/** Whether text on this background should be light. */
function isDark(hex: string): boolean {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
    if (!m) {
        return false;
    }
    const n = parseInt(m[1], 16);
    const lum =
        (0.299 * ((n >> 16) & 255) +
            0.587 * ((n >> 8) & 255) +
            0.114 * (n & 255)) /
        255;

    return lum < 0.5;
}

/**
 * WB1: the design picker. Each card is a small picture of the website in that design's own background, colours and
 * heading font, so brokers can see the difference before they choose.
 */
export function DesignPicker({
    designs,
    value,
    onChange,
    name = 'Your Brokerage',
    previewHref,
}: {
    designs: DesignCard[];
    value: string;
    onChange: (key: string) => void;
    name?: string;
    previewHref?: (key: string) => string | null;
}) {
    return (
        <div
            className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            role="radiogroup"
            aria-label="Website design"
        >
            {designs.map((d) => {
                const theme = d.theme ?? '#ffffff';
                const ink = isDark(theme) ? '#f8fafc' : '#0f172a';
                const chosen = value === d.key;
                const href = previewHref
                    ? previewHref(d.key)
                    : (d.preview ?? null);

                return (
                    <div
                        key={d.key}
                        className={`grid content-start gap-2 rounded-xl border bg-card p-2 transition ${chosen ? 'border-primary ring-2 ring-primary/30' : 'hover:border-foreground/30'}`}
                    >
                        <button
                            type="button"
                            role="radio"
                            aria-checked={chosen}
                            onClick={() => onChange(d.key)}
                            className="grid gap-2 rounded-lg text-left focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                        >
                            <span
                                aria-hidden="true"
                                className="relative grid h-32 content-start gap-2 overflow-hidden rounded-lg border p-3"
                                style={{ background: theme, color: ink }}
                            >
                                <span className="flex items-center gap-2 text-[11px]">
                                    <b
                                        className="truncate"
                                        style={{
                                            fontFamily: `'${d.font ?? 'Inter'}', serif`,
                                        }}
                                    >
                                        {name}
                                    </b>
                                    <span className="ml-auto flex gap-1 opacity-50">
                                        <i className="h-1 w-5 rounded bg-current" />
                                        <i className="h-1 w-5 rounded bg-current" />
                                        <i className="h-1 w-5 rounded bg-current" />
                                    </span>
                                </span>
                                <span
                                    className="mt-2 text-lg leading-tight"
                                    style={{
                                        fontFamily: `'${d.font ?? 'Inter'}', serif`,
                                    }}
                                >
                                    Find your place
                                </span>
                                <span className="flex h-6 overflow-hidden rounded border border-current/20 bg-white/80">
                                    <span className="flex-1" />
                                    <span
                                        className="w-12"
                                        style={{
                                            background: d.primary ?? '#1d4ed8',
                                        }}
                                    />
                                </span>
                                <span
                                    className="absolute right-0 bottom-0 left-0 h-1.5"
                                    style={{
                                        background: d.accent ?? '#0f172a',
                                    }}
                                />
                                {chosen && (
                                    <span className="absolute top-2 right-2 grid size-6 place-items-center rounded-full bg-primary text-primary-foreground">
                                        <Check className="size-4" />
                                    </span>
                                )}
                            </span>
                            <span className="flex items-center gap-2 px-1">
                                <span className="font-medium">{d.name}</span>
                                {d.luxury && (
                                    <Badge variant="outline">Luxury</Badge>
                                )}
                                {chosen && (
                                    <Badge className="ml-auto">Chosen</Badge>
                                )}
                            </span>
                            <span className="px-1 text-xs text-muted-foreground">
                                {d.description}
                            </span>
                        </button>
                        {href && (
                            <a
                                href={href}
                                target="_blank"
                                rel="noreferrer"
                                className="px-1 pb-1 text-xs text-primary underline-offset-2 hover:underline"
                            >
                                Preview {d.name} (opens a new tab)
                            </a>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
