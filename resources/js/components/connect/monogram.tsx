export type AppEntry = {
    key: string;
    name: string;
    category: string;
    category_name: string;
    summary: string;
    scope: 'brokerage' | 'agent' | 'both';
    auth: string;
    syncs: string[];
    cost: string;
    vendor_cost: string | null;
    status: 'available' | 'soon';
    connected: number;
    problem: boolean;
    can_connect: boolean;
    requested: boolean;
};

/** The first letters of an app's name, as its tile (we don't show other companies' logos). */
export function Monogram({ name }: { name: string }) {
    const letters = name
        .replace(/\(.*\)/, '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join('');

    return (
        <span
            aria-hidden="true"
            className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-sm font-semibold text-primary"
        >
            {letters}
        </span>
    );
}
