import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const classes: [string, string][] = [
    ['individual', 'Individual / sole proprietor'],
    ['llc_single', 'Single-member LLC'],
    ['llc_partnership', 'LLC taxed as partnership'],
    ['llc_corporation', 'LLC taxed as corporation'],
    ['s_corp', 'S corporation'],
    ['c_corp', 'C corporation'],
    ['partnership', 'Partnership'],
];

/** Form W-9: collected online, TIN encrypted, signed by typing the legal name. */
export function W9Form({ referral }: { referral?: string }) {
    const form = useForm({
        legal_name: '',
        business_name: '',
        tax_class: 'individual',
        tin_type: 'ssn',
        tin: '',
        address: '',
        signature_name: '',
        certify: false,
        referral: referral ?? '',
    });

    return (
        <form
            className="grid gap-2 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/my-money/w9', {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <Input
                aria-label="Legal name"
                placeholder="Legal name (as on your tax return)"
                value={form.data.legal_name}
                onChange={(e) => form.setData('legal_name', e.target.value)}
            />
            <Input
                aria-label="Business name"
                placeholder="Business name (if different)"
                value={form.data.business_name}
                onChange={(e) => form.setData('business_name', e.target.value)}
            />
            <select
                aria-label="Federal tax classification"
                className="rounded border bg-background px-2 py-1.5"
                value={form.data.tax_class}
                onChange={(e) => form.setData('tax_class', e.target.value)}
            >
                {classes.map(([k, v]) => (
                    <option key={k} value={k}>
                        {v}
                    </option>
                ))}
            </select>
            <span className="flex gap-2">
                <select
                    aria-label="TIN type"
                    className="rounded border bg-background px-2 py-1.5"
                    value={form.data.tin_type}
                    onChange={(e) => form.setData('tin_type', e.target.value)}
                >
                    <option value="ssn">SSN</option>
                    <option value="ein">EIN</option>
                </select>
                <Input
                    aria-label="Taxpayer identification number"
                    placeholder="9 digits"
                    inputMode="numeric"
                    autoComplete="off"
                    value={form.data.tin}
                    onChange={(e) => form.setData('tin', e.target.value)}
                />
            </span>
            <Input
                aria-label="Mailing address"
                placeholder="Mailing address"
                className="sm:col-span-2"
                value={form.data.address}
                onChange={(e) => form.setData('address', e.target.value)}
            />
            <label className="flex items-start gap-2 sm:col-span-2">
                <input
                    type="checkbox"
                    className="mt-1 size-4"
                    checked={form.data.certify}
                    onChange={(e) => form.setData('certify', e.target.checked)}
                />
                <span>
                    Under penalties of perjury, I certify the statements on Form
                    W-9: the number shown is my correct TIN, I am not subject to
                    backup withholding (or have been notified otherwise), and I
                    am a U.S. person.
                </span>
            </label>
            <Input
                aria-label="Signature"
                placeholder="Sign by typing your legal name"
                value={form.data.signature_name}
                onChange={(e) => form.setData('signature_name', e.target.value)}
            />
            <Button
                type="submit"
                disabled={form.processing || !form.data.certify}
            >
                Sign and save W-9
            </Button>
            {Object.values(form.errors)[0] && (
                <p role="alert" className="text-destructive sm:col-span-2">
                    {Object.values(form.errors)[0]}
                </p>
            )}
        </form>
    );
}
