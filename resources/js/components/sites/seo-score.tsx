import { Badge } from '@/components/ui/badge';

export type SeoChecks = {
    score: number;
    checks: { key: string; ok: boolean; weight: number; message: string }[];
};

/** The live SEO and AI-answer check for a page or post (specs/websites/04 and 08). */
export function SeoScore({ check }: { check: SeoChecks | null }) {
    if (check === null) {
        return (
            <p className="text-xs text-muted-foreground">
                Save to see the search and AI-answer check.
            </p>
        );
    }
    const tone =
        check.score >= 80
            ? 'text-emerald-600'
            : check.score >= 50
              ? 'text-amber-600'
              : 'text-destructive';

    return (
        <div className="grid gap-2 text-sm">
            <div className="flex items-center gap-2">
                <span className={`text-2xl font-semibold ${tone}`}>
                    {check.score}
                </span>
                <span className="text-muted-foreground">
                    / 100 search and AI-answer score
                </span>
            </div>
            <ul className="grid gap-1.5">
                {[...check.checks]
                    .sort(
                        (a, b) =>
                            Number(a.ok) - Number(b.ok) || b.weight - a.weight,
                    )
                    .map((c) => (
                        <li key={c.key} className="flex gap-2">
                            <Badge
                                variant={c.ok ? 'secondary' : 'outline'}
                                className="h-5 shrink-0"
                            >
                                {c.ok ? '✓' : 'Fix'}
                            </Badge>
                            <span
                                className={c.ok ? 'text-muted-foreground' : ''}
                            >
                                {c.message}
                            </span>
                        </li>
                    ))}
            </ul>
        </div>
    );
}
