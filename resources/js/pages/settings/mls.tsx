import { Head, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Source = {
    ulid: string;
    name: string;
    states: string[];
    minutes_behind: number | null;
    licence: {
        status: string;
        broker_mls_id: string;
        scopes: string[];
        expires_on: string | null;
        note: string | null;
    } | null;
    rules: {
        program: string;
        sold_allowed: boolean;
        sold_delay_days: number;
        attribution: string;
    } | null;
};

const statusText: Record<string, string> = {
    pending: 'Waiting for RightAlly to confirm with the MLS',
    active: 'Active: listings show on your websites',
    revoked: 'Access ended',
    expired: 'Licence expired: renew your IDX agreement',
};

/** Settings → MLS: request access to an MLS and see the rules that apply. */
export default function MlsSettings({ sources }: { sources: Source[] }) {
    return (
        <>
            <Head title="MLS" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="MLS"
                    description="Choose the MLSs you belong to. Once RightAlly confirms your IDX agreement with the MLS, its listings appear on your websites, with the MLS's rules applied automatically."
                />
                {sources.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No MLS is connected in your area yet. Contact RightAlly
                        support to add yours.
                    </p>
                ) : (
                    <ul className="grid gap-3 text-sm">
                        {sources.map((s) => (
                            <SourceRow key={s.ulid} source={s} />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function SourceRow({ source: s }: { source: Source }) {
    const form = useForm({
        broker_mls_id: s.licence?.broker_mls_id ?? '',
        office_mls_ids: '',
        agreement_ref: '',
    });
    const canRequest =
        s.licence === null || ['revoked', 'expired'].includes(s.licence.status);

    return (
        <li className="grid gap-2 rounded-lg border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{s.name}</span>
                <span className="text-muted-foreground">
                    {s.states.join(', ')}
                </span>
                {s.licence && (
                    <Badge
                        variant={
                            s.licence.status === 'active'
                                ? 'outline'
                                : 'destructive'
                        }
                    >
                        {statusText[s.licence.status] ?? s.licence.status}
                    </Badge>
                )}
                {s.licence?.status === 'active' && (
                    <span className="ml-auto text-xs text-muted-foreground">
                        {s.minutes_behind === null
                            ? ''
                            : `Updated ${s.minutes_behind} min ago`}
                        {s.licence.expires_on
                            ? ` · licence to ${s.licence.expires_on}`
                            : ''}
                    </span>
                )}
            </div>
            {s.licence?.note && (
                <p className="text-muted-foreground">{s.licence.note}</p>
            )}
            {s.rules && (
                <p className="text-xs text-muted-foreground">
                    Rules: {s.rules.program.toUpperCase()} ·{' '}
                    {s.rules.sold_allowed
                        ? `sold listings after ${s.rules.sold_delay_days} days`
                        : 'no sold listings'}{' '}
                    · "{s.rules.attribution}"
                </p>
            )}
            {canRequest && (
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/settings/mls/${s.ulid}/request`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        aria-label="Broker MLS ID"
                        placeholder="Broker MLS ID"
                        className="w-40"
                        value={form.data.broker_mls_id}
                        onChange={(e) =>
                            form.setData('broker_mls_id', e.target.value)
                        }
                    />
                    <Input
                        aria-label="Office MLS IDs"
                        placeholder="Office MLS IDs (optional)"
                        className="w-56"
                        value={form.data.office_mls_ids}
                        onChange={(e) =>
                            form.setData('office_mls_ids', e.target.value)
                        }
                    />
                    <Input
                        aria-label="IDX agreement reference"
                        placeholder="IDX agreement reference (optional)"
                        className="w-64"
                        value={form.data.agreement_ref}
                        onChange={(e) =>
                            form.setData('agreement_ref', e.target.value)
                        }
                    />
                    <Button size="sm" disabled={form.processing}>
                        Request access
                    </Button>
                    {Object.values(form.errors)[0] && (
                        <span className="text-destructive">
                            {Object.values(form.errors)[0]}
                        </span>
                    )}
                </form>
            )}
        </li>
    );
}
