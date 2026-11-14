import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Fix = {
    action: string;
    label: string;
    item?: string;
    document?: string;
    page?: number;
    fact?: string;
    user?: string;
    packet?: string;
    rule?: string;
} | null;
export type Finding = {
    id: number;
    code: string;
    severity: 'blocker' | 'warning' | 'info';
    message: string;
    evidence: {
        document: string;
        filename: string;
        page: number;
        quote: string;
    } | null;
    fix: Fix;
    status: string;
    dismiss_reason: string | null;
};
export type Compliance = {
    score: number | null;
    scanned_at: string | null;
    submitted_at: string | null;
    payout_hold: string | null;
    can_submit: boolean;
    open: Finding[];
    dismissed: Finding[];
};

const tone: Record<Finding['severity'], string> = {
    blocker: 'border-red-300 bg-red-50 text-red-900',
    warning: 'border-amber-300 bg-amber-50 text-amber-900',
    info: 'border-slate-200 bg-slate-50 text-slate-800',
};

const evidenceUrl = (e: NonNullable<Finding['evidence']>) =>
    `/deals/documents/${e.document}/evidence?page=${e.page}&quote=${encodeURIComponent(e.quote)}`;

function FixButton({
    deal,
    fix,
    reviewer,
}: {
    deal: string;
    fix: Fix;
    reviewer: boolean;
}) {
    if (!fix) return null;
    const opts = { preserveScroll: true };
    const link = (href: string) => (
        <Button size="sm" variant="outline" className="bg-background" asChild>
            <a href={href}>{fix.label}</a>
        </Button>
    );
    switch (fix.action) {
        case 'upload':
            return link(`#item-${fix.item}`);
        case 'view':
            return link(
                `/deals/documents/${fix.document}/evidence?page=${fix.page ?? 1}`,
            );
        case 'safe_copy':
            return link(`/deals/documents/${fix.document}/safe`);
        case 'roster':
            return fix.user ? link(`/roster/${fix.user}`) : null;
        case 'packet':
            return link(`/forms/packets/${fix.packet}`);
        case 'cda':
        case 'money':
            return link('#money-desk');
        case 'receipts':
            return link('/finance/receipts');
        case 'title':
            return link('/settings/transactions');
        case 'dates':
            return link('#key-dates');
        case 'apply_fact':
            return (
                <Button
                    size="sm"
                    variant="outline"
                    className="bg-background"
                    onClick={() =>
                        router.post(
                            `/deals/${deal}/facts`,
                            { facts: [fix.fact] },
                            opts,
                        )
                    }
                >
                    {fix.label}
                </Button>
            );
        case 'approve_rule':
            return reviewer ? (
                <Button
                    size="sm"
                    className="bg-background"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            `/deals/${deal}/compliance/rules/${fix.rule}/approve`,
                            {},
                            opts,
                        )
                    }
                >
                    {fix.label}
                </Button>
            ) : null;
        default:
            return null;
    }
}

/**
 * Deal-IQ compliance on a deal: the broker-ready score, every finding with its evidence on the page and a fix,
 * dismissing with a reason, and one-click submission to the broker at 100%.
 */
export function CompliancePanel({
    deal,
    compliance,
    reviewer,
    locked,
}: {
    deal: string;
    compliance: Compliance;
    reviewer: boolean;
    locked: boolean;
}) {
    const [dismissing, setDismissing] = useState<number | null>(null);
    const [reason, setReason] = useState('');
    const opts = { preserveScroll: true };
    const score = compliance.score ?? 0;
    const blockers = compliance.open.filter((f) => f.severity === 'blocker');
    const warnings = compliance.open.filter((f) => f.severity === 'warning');

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <div className="flex flex-wrap items-center gap-3">
                <div
                    className={`grid size-14 place-items-center rounded-full border-4 text-base font-semibold ${score === 100 ? 'border-emerald-500' : score >= 60 ? 'border-amber-400' : 'border-red-400'}`}
                    aria-label={`Broker-ready ${score}%`}
                >
                    {score}%
                </div>
                <div className="grid">
                    <h2 className="font-semibold">Broker-ready check</h2>
                    <p className="text-muted-foreground">
                        {blockers.length} blocker(s) · {warnings.length}{' '}
                        warning(s) ·{' '}
                        {compliance.open.length -
                            blockers.length -
                            warnings.length}{' '}
                        note(s)
                        {compliance.submitted_at && ' · sent to the broker'}
                    </p>
                </div>
                <span className="ml-auto flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                `/deals/${deal}/compliance/scan`,
                                {},
                                opts,
                            )
                        }
                    >
                        Check again
                    </Button>
                    {!locked && !compliance.submitted_at && (
                        <Button
                            size="sm"
                            disabled={!compliance.can_submit}
                            onClick={() =>
                                router.post(
                                    `/deals/${deal}/compliance/submit`,
                                    {},
                                    opts,
                                )
                            }
                        >
                            Send file to the broker
                        </Button>
                    )}
                </span>
            </div>
            {compliance.payout_hold && (
                <p
                    role="alert"
                    className="rounded border border-red-300 bg-red-50 p-2 text-red-900"
                >
                    Payouts for this deal are held: {compliance.payout_hold}{' '}
                    They go ahead automatically once this is resolved.
                </p>
            )}
            {compliance.open.length === 0 && (
                <p className="text-emerald-800">
                    Nothing to fix. Deal-IQ checks again each time the file
                    changes.
                </p>
            )}
            <ul className="grid gap-2">
                {compliance.open.map((f) => (
                    <li
                        key={f.id}
                        className={`grid gap-1 rounded border p-2 ${tone[f.severity]}`}
                    >
                        <span className="flex flex-wrap items-start gap-2">
                            <Badge
                                variant="outline"
                                className="bg-background capitalize"
                            >
                                {f.severity}
                            </Badge>
                            <span className="min-w-0 flex-1">{f.message}</span>
                        </span>
                        {f.evidence && (
                            <a
                                className="text-xs underline"
                                href={evidenceUrl(f.evidence)}
                                target="_blank"
                                rel="noreferrer"
                            >
                                {f.evidence.filename}, page {f.evidence.page}: “
                                {f.evidence.quote}”
                            </a>
                        )}
                        <span className="flex flex-wrap items-center gap-2">
                            <FixButton
                                deal={deal}
                                fix={f.fix}
                                reviewer={reviewer}
                            />
                            {(reviewer || f.severity === 'info') &&
                                (dismissing === f.id ? (
                                    <form
                                        className="flex flex-wrap gap-2"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            router.post(
                                                `/deals/${deal}/compliance/findings/${f.id}/dismiss`,
                                                { reason },
                                                {
                                                    ...opts,
                                                    onSuccess: () => {
                                                        setDismissing(null);
                                                        setReason('');
                                                    },
                                                },
                                            );
                                        }}
                                    >
                                        <Input
                                            aria-label="Why this is not a problem"
                                            placeholder="Why this is not a problem"
                                            className="w-64 bg-background"
                                            value={reason}
                                            onChange={(e) =>
                                                setReason(e.target.value)
                                            }
                                        />
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="bg-background"
                                            disabled={reason.length < 5}
                                        >
                                            Dismiss
                                        </Button>
                                    </form>
                                ) : (
                                    <button
                                        type="button"
                                        className="text-xs underline"
                                        onClick={() => setDismissing(f.id)}
                                    >
                                        Not a problem?
                                    </button>
                                ))}
                        </span>
                    </li>
                ))}
            </ul>
            {compliance.dismissed.length > 0 && (
                <details className="text-xs text-muted-foreground">
                    <summary>{compliance.dismissed.length} dismissed</summary>
                    {compliance.dismissed.map((f) => (
                        <p key={f.id}>
                            {f.message} — {f.dismiss_reason}
                        </p>
                    ))}
                </details>
            )}
        </section>
    );
}
