import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type OfferOptions = {
    financing: Record<string, string>;
    proof: Record<string, string>;
    contingencies: Record<string, string>;
};

/** Terms as stored (cents) → as typed (dollars). */
export type OfferTerms = {
    agent_name?: string | null;
    agent_email?: string | null;
    agent_phone?: string | null;
    brokerage?: string | null;
    buyer_names?: string | null;
    price_cents?: number | null;
    financing?: string | null;
    down_percent?: number | string | null;
    earnest_cents?: number | null;
    option_fee_cents?: number | null;
    option_days?: number | null;
    concessions_cents?: number | null;
    buyer_agent_cents?: number | null;
    closing_date?: string | null;
    contingencies?: string[];
    appraisal_gap_cents?: number | null;
    escalation_cap_cents?: number | null;
    escalation_step_cents?: number | null;
    proof?: string | null;
    leaseback_days?: number | null;
    expires_at?: string | null;
    notes?: string | null;
};

const select = 'rounded border bg-background px-2 py-1';
const dollars = (c?: number | null) =>
    c === null || c === undefined || c === 0 ? '' : String(c / 100);

/**
 * The offer terms (specs/ai/02, §16), used by buyers' agents on the offer link and by the listing agent.
 * No personal letters or photos: they are not shared with the seller (fair housing).
 */
export function OfferForm({
    action,
    options,
    initial = {},
    withFile = false,
    showAgent = true,
    submitLabel,
    onDone,
}: {
    action: string;
    options: OfferOptions;
    initial?: OfferTerms;
    withFile?: boolean;
    showAgent?: boolean;
    submitLabel: string;
    onDone?: () => void;
}) {
    const form = useForm({
        agent_name: initial.agent_name ?? '',
        agent_email: initial.agent_email ?? '',
        agent_phone: initial.agent_phone ?? '',
        brokerage: initial.brokerage ?? '',
        buyer_names: initial.buyer_names ?? '',
        price: dollars(initial.price_cents),
        financing: initial.financing ?? '',
        down_percent: initial.down_percent ? String(initial.down_percent) : '',
        earnest: dollars(initial.earnest_cents),
        option_fee: dollars(initial.option_fee_cents),
        option_days: initial.option_days ? String(initial.option_days) : '',
        concessions: dollars(initial.concessions_cents),
        buyer_agent: dollars(initial.buyer_agent_cents),
        closing_date: initial.closing_date?.slice(0, 10) ?? '',
        contingencies: initial.contingencies ?? [],
        appraisal_gap: dollars(initial.appraisal_gap_cents),
        escalation_cap: dollars(initial.escalation_cap_cents),
        escalation_step: dollars(initial.escalation_step_cents),
        proof: initial.proof ?? '',
        leaseback_days: initial.leaseback_days
            ? String(initial.leaseback_days)
            : '',
        expires_at: initial.expires_at?.slice(0, 16).replace(' ', 'T') ?? '',
        notes: initial.notes ?? '',
        file: null as File | null,
    });
    const field = (
        k:
            | 'agent_name'
            | 'agent_email'
            | 'agent_phone'
            | 'brokerage'
            | 'buyer_names'
            | 'price'
            | 'down_percent'
            | 'earnest'
            | 'option_fee'
            | 'option_days'
            | 'concessions'
            | 'buyer_agent'
            | 'closing_date'
            | 'appraisal_gap'
            | 'escalation_cap'
            | 'escalation_step'
            | 'leaseback_days'
            | 'expires_at',
        label: string,
        type = 'text',
        hint?: string,
    ) => (
        <label className="grid gap-1">
            <span className="text-muted-foreground">{label}</span>
            <Input
                type={type}
                inputMode={type === 'number' ? 'decimal' : undefined}
                value={form.data[k]}
                onChange={(e) => form.setData(k, e.target.value)}
            />
            {hint && (
                <span className="text-xs text-muted-foreground">{hint}</span>
            )}
            {form.errors[k] && (
                <span className="text-destructive">{form.errors[k]}</span>
            )}
        </label>
    );

    return (
        <form
            className="grid gap-3 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(action, {
                    forceFormData: withFile,
                    preserveScroll: true,
                    onSuccess: () => onDone?.(),
                });
            }}
        >
            {showAgent && (
                <fieldset className="grid gap-2 md:grid-cols-2">
                    <legend className="mb-1 font-medium">Buyer's agent</legend>
                    {field('agent_name', 'Name')}
                    {field('agent_email', 'Email', 'email')}
                    {field('agent_phone', 'Phone', 'tel')}
                    {field('brokerage', 'Brokerage')}
                    {field(
                        'buyer_names',
                        'Buyers (as they will sign)',
                        'text',
                        'Not shown to the seller in the comparison.',
                    )}
                </fieldset>
            )}
            <fieldset className="grid gap-2 md:grid-cols-3">
                <legend className="mb-1 font-medium">Price and money</legend>
                {field('price', 'Offer price ($)', 'number')}
                <label className="grid gap-1">
                    <span className="text-muted-foreground">Financing</span>
                    <select
                        className={select}
                        value={form.data.financing}
                        onChange={(e) =>
                            form.setData('financing', e.target.value)
                        }
                    >
                        <option value="">Choose…</option>
                        {Object.entries(options.financing).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                </label>
                {field('down_percent', 'Down payment (%)', 'number')}
                <label className="grid gap-1">
                    <span className="text-muted-foreground">
                        Pre-approval or proof of funds
                    </span>
                    <select
                        className={select}
                        value={form.data.proof}
                        onChange={(e) => form.setData('proof', e.target.value)}
                    >
                        <option value="">Choose…</option>
                        {Object.entries(options.proof).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                </label>
                {field('earnest', 'Earnest money ($)', 'number')}
                {field('option_fee', 'Option fee ($)', 'number')}
                {field('concessions', 'Seller concessions asked ($)', 'number')}
                {field(
                    'buyer_agent',
                    "Buyer's agent pay asked of the seller ($)",
                    'number',
                )}
                {field('appraisal_gap', 'Appraisal gap covered ($)', 'number')}
                {field(
                    'escalation_cap',
                    'Escalation: up to ($)',
                    'number',
                    'Leave blank for no escalation clause.',
                )}
                {field(
                    'escalation_step',
                    'Escalation: above the best offer by ($)',
                    'number',
                )}
            </fieldset>
            <fieldset className="grid gap-2 md:grid-cols-3">
                <legend className="mb-1 font-medium">
                    Dates and conditions
                </legend>
                {field('closing_date', 'Closing date', 'date')}
                {field('option_days', 'Option or inspection days', 'number')}
                {field('leaseback_days', 'Seller leaseback days', 'number')}
                {field('expires_at', 'Offer expires', 'datetime-local')}
                <div className="grid gap-1 md:col-span-2">
                    <span className="text-muted-foreground">Contingent on</span>
                    <span className="flex flex-wrap gap-3">
                        {Object.entries(options.contingencies).map(([k, l]) => (
                            <label key={k} className="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    checked={form.data.contingencies.includes(
                                        k,
                                    )}
                                    onChange={(e) =>
                                        form.setData(
                                            'contingencies',
                                            e.target.checked
                                                ? [
                                                      ...form.data
                                                          .contingencies,
                                                      k,
                                                  ]
                                                : form.data.contingencies.filter(
                                                      (x) => x !== k,
                                                  ),
                                        )
                                    }
                                />
                                {l}
                            </label>
                        ))}
                    </span>
                </div>
            </fieldset>
            <label className="grid gap-1">
                <span className="text-muted-foreground">Other terms</span>
                <Input
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                />
                {form.errors.notes && (
                    <span className="text-destructive">
                        {form.errors.notes}
                    </span>
                )}
            </label>
            {withFile && (
                <label className="grid gap-1">
                    <span className="text-muted-foreground">
                        The signed offer (PDF), optional
                    </span>
                    <input
                        type="file"
                        accept="application/pdf,image/*"
                        onChange={(e) =>
                            form.setData('file', e.target.files?.[0] ?? null)
                        }
                    />
                    <span className="text-xs text-muted-foreground">
                        Please do not include personal letters or photos of the
                        buyers: they are not shared with the seller (fair
                        housing).
                    </span>
                </label>
            )}
            {form.errors.price && (
                <p className="text-destructive">{form.errors.price}</p>
            )}
            {form.errors.escalation_cap && (
                <p className="text-destructive">{form.errors.escalation_cap}</p>
            )}
            <Button
                type="submit"
                className="justify-self-start"
                disabled={form.processing}
            >
                {submitLabel}
            </Button>
        </form>
    );
}
