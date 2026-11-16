import { Head, router } from '@inertiajs/react';

import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { StatCard } from '@/components/ui/stat-card';
import { usd } from '@/lib/cents';

type Props = {
    month: string;
    label: string;
    hours_saved: number;
    automations: { runs: number; steps: number };
    ai_replies: number;
    documents_read: number;
    leads: {
        count: number;
        median_minutes: number | null;
        within_target: number | null;
    };
    closings: { sides: number; gci_cents: number };
    website: {
        leads: number;
        closed: number;
        company_dollar_cents: number;
    } | null;
    minutes: Record<string, number>;
};

/** Your month with RightAlly (specs/insights/01, K3). */
export default function Roi(p: Props) {
    return (
        <>
            <Head title={`${p.label} with RightAlly`} />
            <div className="flex flex-col gap-4 p-4 text-sm md:p-6">
                <PageHeader
                    title={`${p.label} with RightAlly`}
                    description="What RightAlly did for your brokerage. Owners get this by email on the 1st of each month."
                    actions={
                        <Input
                            type="month"
                            aria-label="Month"
                            value={p.month}
                            onChange={(e) =>
                                e.target.value &&
                                router.get('/insights/roi', {
                                    month: e.target.value,
                                })
                            }
                        />
                    }
                />
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Hours of work saved"
                        value={String(p.hours_saved)}
                    />
                    <StatCard
                        label="Automation steps done"
                        value={String(p.automations.steps)}
                    />
                    <StatCard
                        label="AI replies to leads"
                        value={String(p.ai_replies)}
                    />
                    <StatCard
                        label="Documents read"
                        value={String(p.documents_read)}
                    />
                </div>
                <div className="grid gap-3 sm:grid-cols-3">
                    <StatCard label="Leads" value={String(p.leads.count)} />
                    <StatCard
                        label="Median first reply"
                        value={
                            p.leads.median_minutes === null
                                ? '—'
                                : `${p.leads.median_minutes} min`
                        }
                    />
                    <StatCard
                        label="Closings"
                        value={`${p.closings.sides} · ${usd(p.closings.gci_cents)} GCI`}
                    />
                </div>
                {p.website && (
                    <p>
                        Your website brought {p.website.leads} leads and{' '}
                        {p.website.closed} closings (
                        {usd(p.website.company_dollar_cents)} company dollar).
                    </p>
                )}
                <p className="text-muted-foreground">
                    Hours saved count {p.minutes.automation_step} minutes per
                    automation step, {p.minutes.ai_reply} per AI reply and{' '}
                    {p.minutes.document_read} per document read: what each takes
                    by hand.
                </p>
            </div>
        </>
    );
}

Roi.layout = {
    breadcrumbs: [
        { title: 'Your month with RightAlly', href: '/insights/roi' },
    ],
};
