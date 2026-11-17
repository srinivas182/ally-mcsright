import { useForm } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

import type { NetworkCard } from './types';

/** Send a referral to one agent. Client details go only to them, and only once they accept. */
export function SendForm({
    to,
    area,
    side,
    fee,
    onDone,
}: {
    to: NetworkCard;
    area: string;
    side: string | null;
    fee: string;
    onDone: () => void;
}) {
    const form = useForm({
        to: to.ulid,
        side: side ?? 'buyer',
        area,
        price_band: '',
        timing: '',
        language: '',
        needs: '',
        fee_percent: fee,
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        client_agreed: false,
    });

    return (
        <form
            className="mt-2 grid w-full gap-3 rounded-lg border bg-muted/30 p-4 text-base"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/network/referrals', { onSuccess: onDone });
            }}
        >
            <h3 className="font-semibold">Send a referral to {to.name}</h3>
            <fieldset className="flex flex-wrap gap-4">
                <legend className="mb-1">The client is</legend>
                {(['buyer', 'seller'] as const).map((s) => (
                    <label key={s} className="flex items-center gap-2">
                        <input
                            type="radio"
                            name="side"
                            checked={form.data.side === s}
                            onChange={() => form.setData('side', s)}
                        />
                        {s === 'buyer' ? 'Buying' : 'Selling'}
                    </label>
                ))}
            </fieldset>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="grid gap-1">
                    Where
                    <Input
                        value={form.data.area}
                        onChange={(e) => form.setData('area', e.target.value)}
                        required
                    />
                </label>
                <label className="grid gap-1">
                    Price range (optional)
                    <Input
                        value={form.data.price_band}
                        placeholder="e.g. $400k to $500k"
                        onChange={(e) =>
                            form.setData('price_band', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1">
                    When (optional)
                    <Input
                        value={form.data.timing}
                        placeholder="e.g. this spring"
                        onChange={(e) => form.setData('timing', e.target.value)}
                    />
                </label>
                <label className="grid gap-1">
                    Language (optional)
                    <Input
                        value={form.data.language}
                        onChange={(e) =>
                            form.setData('language', e.target.value)
                        }
                    />
                </label>
            </div>
            <label className="grid gap-1">
                What they need (optional)
                <textarea
                    className="min-h-20 rounded-md border bg-transparent p-2"
                    maxLength={600}
                    value={form.data.needs}
                    onChange={(e) => form.setData('needs', e.target.value)}
                />
            </label>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="grid gap-1">
                    Client's first name
                    <Input
                        value={form.data.first_name}
                        onChange={(e) =>
                            form.setData('first_name', e.target.value)
                        }
                        required
                    />
                </label>
                <label className="grid gap-1">
                    Last name (optional)
                    <Input
                        value={form.data.last_name}
                        onChange={(e) =>
                            form.setData('last_name', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1">
                    Email
                    <Input
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                </label>
                <label className="grid gap-1">
                    Phone
                    <Input
                        type="tel"
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                    />
                </label>
            </div>
            <InputError message={form.errors.email} />
            <label className="grid max-w-xs gap-1">
                Referral fee (% of their side's commission)
                <Input
                    inputMode="decimal"
                    value={form.data.fee_percent}
                    onChange={(e) =>
                        form.setData('fee_percent', e.target.value)
                    }
                />
            </label>
            <InputError message={form.errors.fee_percent} />
            <label className="flex items-start gap-2">
                <input
                    type="checkbox"
                    className="mt-1"
                    checked={form.data.client_agreed}
                    onChange={(e) =>
                        form.setData('client_agreed', e.target.checked)
                    }
                />
                My client agreed to me passing on their details to this agent.
            </label>
            <InputError message={form.errors.client_agreed} />
            <InputError
                message={
                    (form.errors as Record<string, string>).to ??
                    (form.errors as Record<string, string>).network ??
                    form.errors.side
                }
            />
            <p className="text-sm text-muted-foreground">
                {to.name} sees only what the client needs until they accept.
                RightAlly takes no share of the referral fee.
            </p>
            <div className="flex gap-2">
                <Button type="submit" size="lg" disabled={form.processing}>
                    Send referral
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
