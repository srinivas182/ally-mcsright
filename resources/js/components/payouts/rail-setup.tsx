import { router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type RailInfo = {
    rail: string;
    status: string;
    same_day_default: boolean;
    offered: string[];
    steps: {
        business: boolean;
        bank: boolean;
        verified: boolean;
        financial_account: boolean;
        mt: boolean;
    };
};

const labels: Record<string, string> = {
    bank_file: 'Bank file (upload to my bank)',
    dwolla: 'Dwolla (automatic)',
    modern_treasury: 'Modern Treasury (automatic)',
    stripe: 'Stripe (automatic)',
};

/** The brokerage chooses how it pays and links its own bank through the partner. */
export function RailSetup({ info }: { info: RailInfo }) {
    const choose = useForm({
        rail: info.rail,
        same_day: info.same_day_default,
    });
    const biz = useForm({
        businessName: '',
        businessType: 'llc',
        ein: '',
        address1: '',
        city: '',
        state: '',
        postalCode: '',
        email: '',
        controller_first: '',
        controller_last: '',
        controller_title: '',
        controller_dob: '',
        controller_ssn4: '',
    });
    const bank = useForm({ routing: '', account: '', type: 'checking' });
    const verify = useForm({ amount1: '', amount2: '' });
    const mt = useForm({
        org_id: '',
        api_key: '',
        webhook_key: '',
        internal_account_id: '',
    });
    const opts = { preserveScroll: true };
    const field = (
        f: ReturnType<typeof useForm<Record<string, string>>>,
        key: string,
        label: string,
        type = 'text',
    ) => (
        <Input
            key={key}
            aria-label={label}
            placeholder={label}
            type={type}
            autoComplete="off"
            value={String(f.data[key] ?? '')}
            onChange={(e) => f.setData(key, e.target.value)}
        />
    );

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <h2 className="flex items-center gap-2 font-semibold">
                How we pay agents{' '}
                <Badge
                    variant={info.status === 'ready' ? 'secondary' : 'outline'}
                >
                    {info.status === 'ready' ? 'Ready' : 'Setting up'}
                </Badge>
            </h2>
            <p className="text-muted-foreground">
                Money always goes from your own bank account. RightAlly never
                holds it, and you approve every payout run.
            </p>
            <form
                className="flex flex-wrap items-center gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    choose.post('/finance/payout-rail', opts);
                }}
            >
                <select
                    aria-label="Payout method"
                    className="rounded border bg-background px-2 py-1.5"
                    value={choose.data.rail}
                    onChange={(e) => choose.setData('rail', e.target.value)}
                >
                    {['bank_file', ...info.offered].map((r) => (
                        <option key={r} value={r}>
                            {labels[r]}
                        </option>
                    ))}
                </select>
                <label className="flex items-center gap-1">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={choose.data.same_day}
                        onChange={(e) =>
                            choose.setData('same_day', e.target.checked)
                        }
                    />{' '}
                    Same-day by default
                </label>
                <Button type="submit" size="sm" variant="outline">
                    Save
                </Button>
            </form>

            {info.rail === 'dwolla' && (
                <div className="grid gap-2">
                    <p className="font-medium">
                        1. Verify your business with Dwolla{' '}
                        {info.steps.business && '✓'}
                    </p>
                    {!info.steps.business && (
                        <form
                            className="grid gap-2 sm:grid-cols-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                biz.post(
                                    '/finance/payout-rail/dwolla/business',
                                    opts,
                                );
                            }}
                        >
                            {field(biz, 'businessName', 'Business name')}
                            {field(biz, 'ein', 'EIN')}
                            {field(biz, 'email', 'Business email')}
                            {field(biz, 'address1', 'Address')}
                            {field(biz, 'city', 'City')}
                            {field(biz, 'state', 'State (2 letters)')}
                            {field(biz, 'postalCode', 'ZIP')}
                            {field(
                                biz,
                                'controller_first',
                                'Controller first name',
                            )}
                            {field(
                                biz,
                                'controller_last',
                                'Controller last name',
                            )}
                            {field(biz, 'controller_title', 'Controller title')}
                            {field(
                                biz,
                                'controller_dob',
                                'Controller date of birth',
                                'date',
                            )}
                            {field(
                                biz,
                                'controller_ssn4',
                                'Controller SSN last 4',
                            )}
                            <p className="text-xs text-muted-foreground sm:col-span-3">
                                These details go straight to Dwolla for
                                verification; RightAlly does not keep them.
                            </p>
                            <Button
                                type="submit"
                                size="sm"
                                className="justify-self-start"
                            >
                                Verify business
                            </Button>
                        </form>
                    )}
                    <p className="font-medium">
                        2. Link your operating account {info.steps.bank && '✓'}
                    </p>
                    {info.steps.business && !info.steps.bank && (
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                bank.post(
                                    '/finance/payout-rail/dwolla/bank',
                                    opts,
                                );
                            }}
                        >
                            {field(bank, 'routing', 'Routing number')}
                            {field(bank, 'account', 'Account number')}
                            <Button type="submit" size="sm">
                                Link account
                            </Button>
                        </form>
                    )}
                    <p className="font-medium">
                        3. Confirm the two small deposits{' '}
                        {info.steps.verified && '✓'}
                    </p>
                    {info.steps.bank && !info.steps.verified && (
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                verify.post(
                                    '/finance/payout-rail/dwolla/verify',
                                    opts,
                                );
                            }}
                        >
                            {field(
                                verify,
                                'amount1',
                                'First amount (e.g. 0.03)',
                            )}
                            {field(verify, 'amount2', 'Second amount')}
                            <Button type="submit" size="sm">
                                Confirm
                            </Button>
                        </form>
                    )}
                </div>
            )}
            {info.rail === 'modern_treasury' && !info.steps.mt && (
                <form
                    className="grid gap-2 sm:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        mt.post('/finance/payout-rail/modern-treasury', opts);
                    }}
                >
                    {field(mt, 'org_id', 'Organisation ID')}
                    {field(mt, 'api_key', 'API key', 'password')}
                    {field(mt, 'webhook_key', 'Webhook key', 'password')}
                    {field(
                        mt,
                        'internal_account_id',
                        'Internal account ID (your bank account)',
                    )}
                    <Button
                        type="submit"
                        size="sm"
                        className="justify-self-start"
                    >
                        Connect Modern Treasury
                    </Button>
                </form>
            )}
            {info.rail === 'stripe' && !info.steps.financial_account && (
                <Button
                    size="sm"
                    className="justify-self-start"
                    onClick={() =>
                        router.post('/finance/payout-rail/stripe', {}, opts)
                    }
                >
                    Open a Stripe Treasury account for payouts
                </Button>
            )}
        </section>
    );
}
