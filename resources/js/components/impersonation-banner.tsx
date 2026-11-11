import { router, usePage } from '@inertiajs/react';
import { EyeIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';

/** Always visible while viewing as someone else. */
export function ImpersonationBanner() {
    const { impersonation } = usePage().props;
    if (!impersonation) {
        return null;
    }

    const until = new Date(impersonation.expires_at * 1000).toLocaleTimeString(
        undefined,
        { hour: 'numeric', minute: '2-digit' },
    );

    return (
        <div
            role="status"
            className="sticky top-0 z-50 flex flex-wrap items-center justify-center gap-3 bg-amber-500 px-4 py-2 text-sm font-medium text-amber-950"
        >
            <EyeIcon aria-hidden="true" className="size-4" />
            <span>
                Viewing as {impersonation.target} ({impersonation.by}).
                Passwords, security, email and payouts are locked. Ends at{' '}
                {until}.
            </span>
            <Button
                size="sm"
                variant="secondary"
                onClick={() => router.post('/impersonate/stop')}
            >
                Stop
            </Button>
        </div>
    );
}
