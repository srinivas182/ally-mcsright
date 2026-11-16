import { Head } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/page-header';

type Row = {
    ulid: string;
    at: string;
    brokerage: string;
    name: string;
    email: string;
    state: string | null;
    agents: number;
    score: number;
    website: string | null;
    saves_cents: number | null;
    contact_ok: boolean;
    viewed: boolean;
};

const usd = (cents: number) =>
    (cents / 100).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    });

/** HQ → Health checks: every free Brokerage Health Check, and who asked to be contacted. */
export default function HqHealthChecks({ checks }: { checks: Row[] }) {
    return (
        <>
            <Head title="Health checks" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Health checks"
                    description="Brokerages that ran the free Health Check. Only contact the ones marked “Wants a call”; the others asked for the report only."
                />
                {checks.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No health checks yet. The public page is /health-check.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="text-muted-foreground">
                                <tr>
                                    <th className="py-2 font-normal">When</th>
                                    <th className="py-2 font-normal">
                                        Brokerage
                                    </th>
                                    <th className="py-2 font-normal">Person</th>
                                    <th className="py-2 font-normal">Agents</th>
                                    <th className="py-2 font-normal">Score</th>
                                    <th className="py-2 font-normal">
                                        Best saving / month
                                    </th>
                                    <th className="py-2 font-normal">
                                        Contact
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {checks.map((c) => (
                                    <tr key={c.ulid} className="border-t">
                                        <td className="py-2">
                                            {new Date(
                                                c.at,
                                            ).toLocaleDateString()}
                                        </td>
                                        <td className="py-2">
                                            <a
                                                className="underline"
                                                href={`/hq/health-checks/${c.ulid}`}
                                            >
                                                {c.brokerage}
                                            </a>
                                            {c.state && ` · ${c.state}`}
                                            {c.website && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {c.website}
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2">
                                            {c.name}
                                            <span className="block text-xs text-muted-foreground">
                                                {c.email}
                                            </span>
                                        </td>
                                        <td className="py-2">{c.agents}</td>
                                        <td className="py-2">{c.score}</td>
                                        <td className="py-2">
                                            {c.saves_cents === null
                                                ? '—'
                                                : usd(c.saves_cents)}
                                        </td>
                                        <td className="py-2">
                                            {c.contact_ok ? (
                                                <Badge>Wants a call</Badge>
                                            ) : (
                                                <Badge variant="outline">
                                                    Report only
                                                </Badge>
                                            )}
                                            {!c.viewed && (
                                                <span className="block text-xs text-muted-foreground">
                                                    Report not opened yet
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}
