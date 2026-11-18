import { Link, usePage } from '@inertiajs/react';

/**
 * SX3: during the grace period for two-step sign-in, a reminder on every page with the days left and one link to
 * set up a passkey (the fingerprint, face or PIN the person already uses on their phone or computer).
 */
export function SecondFactorReminder() {
    const security = usePage().props.security;
    const days = security?.secondFactorDaysLeft ?? null;
    if (days === null) {
        return null;
    }

    return (
        <div
            role="status"
            className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-sky-100 px-4 py-2 text-center text-base text-sky-950 dark:bg-sky-950 dark:text-sky-100"
        >
            <span>
                Keep your clients safe: add a second step to your sign-in.{' '}
                {days === 1 ? 'Today is the last day.' : `${days} days left.`}
            </span>
            <Link
                href="/settings/security#passkeys"
                className="font-medium underline"
            >
                Set it up (1 minute)
            </Link>
        </div>
    );
}
