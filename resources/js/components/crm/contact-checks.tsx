import { router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

export type Checks = {
    email: 'valid' | 'invalid' | 'risky' | null;
    phone: 'mobile' | 'landline' | 'voip' | 'invalid' | null;
    caller_name: string | null;
    moved: string | null;
};

const EMAIL = {
    valid: 'Email works',
    invalid: 'Email bounces',
    risky: 'Email may not reach them',
};

const PHONE = {
    mobile: 'Mobile',
    landline: 'Landline: texts won’t arrive',
    voip: 'Internet phone',
    invalid: 'Phone number doesn’t work',
};

/** LN4 (E24): what the email and phone checks found, a name to fill in, and "may have moved". */
export function ContactChecksPanel({
    base,
    checks,
}: {
    base: string;
    checks: Checks;
}) {
    if (
        checks.email === null &&
        checks.phone === null &&
        checks.caller_name === null &&
        checks.moved === null
    ) {
        return null;
    }

    return (
        <section className="grid gap-2 rounded-lg border p-4">
            <p className="text-sm font-medium">Contact checks</p>
            <div className="flex flex-wrap gap-2">
                {checks.email && (
                    <Badge
                        variant={
                            checks.email === 'valid' ? 'outline' : 'destructive'
                        }
                    >
                        {EMAIL[checks.email]}
                    </Badge>
                )}
                {checks.phone && (
                    <Badge
                        variant={
                            checks.phone === 'invalid'
                                ? 'destructive'
                                : 'outline'
                        }
                    >
                        {PHONE[checks.phone]}
                    </Badge>
                )}
            </div>
            {checks.moved && <p className="text-sm">{checks.moved}</p>}
            {checks.caller_name && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span>
                        The phone network lists this number as{' '}
                        <strong>{checks.caller_name}</strong>.
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                `${base}/use-caller-name`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Use this name
                    </Button>
                </div>
            )}
        </section>
    );
}
