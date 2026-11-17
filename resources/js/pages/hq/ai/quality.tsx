import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Set = {
    prompt: string;
    feature: string;
    cases: number;
    version: string;
    score: number | null;
    scored_version: string | null;
    scored_at: string | null;
    failures: { case: string; why: string[] }[];
};

type Run = {
    ulid: string;
    status: string;
    cases: number;
    passed: number;
    cost_cents: number;
    started_at: string;
};

type Props = {
    sets: Set[];
    untested: string[];
    runs: Run[];
    budget_cents: number;
};

/** AQ1: each AI prompt's test set, its latest score against real models, and what failed. */
export default function AiQuality({
    sets,
    untested,
    runs,
    budget_cents,
}: Props) {
    return (
        <>
            <Head title="AI quality" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="AI quality"
                    description="Every AI feature is tested against realistic cases. It must stay on the facts given, follow Fair Housing, keep to the length asked, and never invent prices or contact details. Tests run every Monday and whenever you press Test now."
                    actions={
                        <Button
                            size="lg"
                            onClick={() =>
                                router.post(
                                    '/hq/ai/quality/run',
                                    {},
                                    {
                                        preserveScroll: true,
                                    },
                                )
                            }
                        >
                            Test now (up to {usd(budget_cents)})
                        </Button>
                    }
                />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Prompt</th>
                                <th className="p-3 font-medium">Cases</th>
                                <th className="p-3 font-medium">Score</th>
                                <th className="p-3 font-medium">What failed</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sets.map((s) => (
                                <tr
                                    key={s.prompt}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        <p className="font-medium">
                                            {s.prompt}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {s.feature} · version {s.version}
                                        </p>
                                    </td>
                                    <td className="p-3">{s.cases}</td>
                                    <td className="p-3">
                                        {s.score === null ? (
                                            <Badge variant="secondary">
                                                Not tested yet
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant={
                                                    s.score === 100
                                                        ? 'default'
                                                        : 'destructive'
                                                }
                                            >
                                                {s.score}%
                                            </Badge>
                                        )}
                                        {s.scored_version &&
                                            s.scored_version !== s.version && (
                                                <p className="text-xs text-muted-foreground">
                                                    Score is for version{' '}
                                                    {s.scored_version}
                                                </p>
                                            )}
                                    </td>
                                    <td className="p-3">
                                        {s.failures.length === 0 ? (
                                            <span className="text-muted-foreground">
                                                Nothing
                                            </span>
                                        ) : (
                                            <ul className="grid gap-1">
                                                {s.failures.map((f) => (
                                                    <li key={f.case}>
                                                        <span className="font-medium">
                                                            {f.case}:
                                                        </span>{' '}
                                                        {f.why.join(' ')}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {untested.length > 0 && (
                    <section aria-labelledby="untested" className="grid gap-2">
                        <h2 id="untested" className="text-lg font-semibold">
                            Prompts without a test set yet ({untested.length})
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            This list can only get shorter: a new prompt can't
                            be added without its test set.
                        </p>
                        <p className="text-sm">{untested.join(', ')}</p>
                    </section>
                )}

                <section aria-labelledby="runs" className="grid gap-2">
                    <h2 id="runs" className="text-lg font-semibold">
                        Recent test runs
                    </h2>
                    <ul className="grid gap-2 text-sm">
                        {runs.map((r) => (
                            <li key={r.ulid}>
                                {r.started_at.slice(0, 16)}: {r.passed} of{' '}
                                {r.cases} passed · {usd(r.cost_cents)} ·{' '}
                                {r.status.replace('_', ' ')}
                            </li>
                        ))}
                        {runs.length === 0 && (
                            <li className="text-muted-foreground">
                                No runs yet.
                            </li>
                        )}
                    </ul>
                </section>
            </div>
        </>
    );
}

AiQuality.layout = {
    breadcrumbs: [{ title: 'AI quality', href: '/hq/ai/quality' }],
};
