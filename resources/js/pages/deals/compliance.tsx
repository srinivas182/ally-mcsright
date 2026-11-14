import { Head, Link, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Stats = {
    fixed: number;
    dismissed: number;
    open: number;
    accuracy: number | null;
    review: boolean;
};
type Props = {
    checks: {
        code: string;
        label: string;
        default: string;
        enabled: boolean;
        severity: string;
        stats: Stats;
    }[];
    rules: {
        ulid: string;
        text: string;
        summary: string;
        active: boolean;
        confirmed_at: string | null;
    }[];
    rule_stats: Stats | null;
    submitted: {
        ulid: string;
        street: string;
        compliance_score: number | null;
        file_submitted_at: string;
    }[];
    at_risk: {
        ulid: string;
        street: string;
        status: string;
        compliance_score: number | null;
    }[];
};

/** The broker's Deal-IQ compliance: checks and how often each is right, plain-English rules, and files. */
export default function ComplianceSettings({
    checks,
    rules,
    rule_stats,
    submitted,
    at_risk,
}: Props) {
    const draft = useForm({ text: '' });
    const opts = { preserveScroll: true };
    const save = (code: string, enabled: boolean, severity: string) =>
        router.post(
            `/deals/compliance/checks/${code}`,
            { enabled, severity },
            opts,
        );

    return (
        <>
            <Head title="Compliance" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Compliance"
                    description="Deal-IQ checks every file as it changes. Turn checks on or off, set how serious each is, and add your own rules in plain English."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/deals/review">Review queue</Link>
                        </Button>
                    }
                />

                <section className="grid gap-2 text-sm">
                    <h2 className="font-semibold">Files sent for review</h2>
                    {submitted.length === 0 ? (
                        <p className="text-muted-foreground">None waiting.</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {submitted.map((d) => (
                                <li
                                    key={d.ulid}
                                    className="flex flex-wrap gap-2 p-2"
                                >
                                    <Link
                                        className="underline"
                                        href={`/deals/${d.ulid}`}
                                    >
                                        {d.street}
                                    </Link>
                                    <Badge variant="secondary">
                                        {d.compliance_score ?? 0}%
                                    </Badge>
                                    <span className="text-muted-foreground">
                                        sent {d.file_submitted_at}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {at_risk.length > 0 && (
                    <section className="grid gap-2 text-sm">
                        <h2 className="font-semibold">
                            Open files not broker-ready
                        </h2>
                        <ul className="divide-y rounded-lg border">
                            {at_risk.map((d) => (
                                <li
                                    key={d.ulid}
                                    className="flex flex-wrap gap-2 p-2"
                                >
                                    <Link
                                        className="underline"
                                        href={`/deals/${d.ulid}`}
                                    >
                                        {d.street}
                                    </Link>
                                    <Badge variant="outline">
                                        {d.compliance_score ?? 0}%
                                    </Badge>
                                    <span className="text-muted-foreground capitalize">
                                        {d.status.replace('_', ' ')}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="grid gap-2 text-sm">
                    <h2 className="font-semibold">Your rules</h2>
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            draft.post('/deals/compliance/rules', {
                                ...opts,
                                onSuccess: () => draft.reset(),
                            });
                        }}
                    >
                        <textarea
                            aria-label="New rule"
                            className="min-h-16 rounded border bg-background p-2"
                            placeholder="e.g. Listings over $1,000,000 need broker approval before going live."
                            value={draft.data.text}
                            onChange={(e) =>
                                draft.setData('text', e.target.value)
                            }
                        />
                        <Button
                            size="sm"
                            className="justify-self-start"
                            disabled={
                                draft.processing || draft.data.text.length < 10
                            }
                        >
                            Read my rule
                        </Button>
                        {Object.values(draft.errors)[0] && (
                            <p role="alert" className="text-destructive">
                                {Object.values(draft.errors)[0]}
                            </p>
                        )}
                    </form>
                    {rules.map((r) => (
                        <div
                            key={r.ulid}
                            className="grid gap-1 rounded-lg border p-3"
                        >
                            <p className="text-muted-foreground">
                                You wrote: “{r.text}”
                            </p>
                            <p>
                                <span className="font-medium">
                                    RightAlly will check:
                                </span>{' '}
                                {r.summary}
                            </p>
                            <span className="flex gap-2">
                                {r.active ? (
                                    <>
                                        <Badge variant="secondary">On</Badge>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    `/deals/compliance/rules/${r.ulid}/off`,
                                                    {},
                                                    opts,
                                                )
                                            }
                                        >
                                            Turn off
                                        </Button>
                                    </>
                                ) : (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/deals/compliance/rules/${r.ulid}/confirm`,
                                                {},
                                                opts,
                                            )
                                        }
                                    >
                                        {r.confirmed_at
                                            ? 'Turn on'
                                            : 'That is right, turn it on'}
                                    </Button>
                                )}
                            </span>
                        </div>
                    ))}
                    {rule_stats && (
                        <p className="text-xs text-muted-foreground">
                            Your rules: {rule_stats.fixed} fixed,{' '}
                            {rule_stats.dismissed} dismissed.
                        </p>
                    )}
                </section>

                <section className="grid gap-2 text-sm">
                    <h2 className="font-semibold">Built-in checks</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[640px]">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="p-2">Check</th>
                                    <th className="p-2">On</th>
                                    <th className="p-2">Severity</th>
                                    <th className="p-2">How often right</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {checks.map((c) => (
                                    <tr key={c.code}>
                                        <td className="p-2">
                                            {c.label}
                                            {c.stats.review && (
                                                <p className="text-xs text-amber-800">
                                                    Often dismissed: consider
                                                    lowering its severity or
                                                    turning it off.
                                                </p>
                                            )}
                                        </td>
                                        <td className="p-2">
                                            <input
                                                type="checkbox"
                                                className="size-4"
                                                aria-label={`${c.label} on`}
                                                checked={c.enabled}
                                                onChange={(e) =>
                                                    save(
                                                        c.code,
                                                        e.target.checked,
                                                        c.severity,
                                                    )
                                                }
                                            />
                                        </td>
                                        <td className="p-2">
                                            <select
                                                aria-label={`${c.label} severity`}
                                                className="rounded border bg-background px-2 py-1"
                                                value={c.severity}
                                                onChange={(e) =>
                                                    save(
                                                        c.code,
                                                        c.enabled,
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="blocker">
                                                    Blocker
                                                </option>
                                                <option value="warning">
                                                    Warning
                                                </option>
                                                <option value="info">
                                                    Note
                                                </option>
                                            </select>
                                        </td>
                                        <td className="p-2 text-muted-foreground">
                                            {c.stats.accuracy === null
                                                ? 'Not judged yet'
                                                : `${c.stats.accuracy}% (${c.stats.fixed} fixed, ${c.stats.dismissed} dismissed)`}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}
