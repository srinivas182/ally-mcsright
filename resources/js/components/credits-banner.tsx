import { Link, usePage } from '@inertiajs/react';

type Credits = {
    available: number;
    scope: 'brokerage' | 'you';
    enforced: boolean;
    maps_paused?: { since: string; missed: number } | null;
} | null;

/**
 * For owners and staff: the brokerage's website map is paused, or its credits ran out (while credits are
 * enforced). Says what it costs them and links to adding credits; nothing in the back office is blocked.
 */
export function CreditsBanner() {
    const credits = usePage().props.credits as Credits | undefined;
    if (!credits || credits.scope !== 'brokerage') {
        return null;
    }
    const paused = credits.maps_paused;
    const out = credits.enforced && credits.available <= 0;
    if (!paused && !out) {
        return null;
    }
    const since = paused
        ? new Date(paused.since).toLocaleDateString('en-US', {
              month: 'short',
              day: 'numeric',
          })
        : '';

    return (
        <div
            role="status"
            className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-amber-100 px-4 py-2 text-center text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
        >
            <span>
                {paused
                    ? `Your website map is paused. ${paused.missed.toLocaleString()} ${paused.missed === 1 ? 'visitor' : 'visitors'} tried to open it since ${since}.`
                    : 'Your brokerage is out of RightAlly credits. AI and extras that use credits are paused.'}
            </span>
            <Link
                href="/settings/credits#buy"
                className="font-medium underline"
            >
                Add credits or turn on auto top-up
            </Link>
        </div>
    );
}
