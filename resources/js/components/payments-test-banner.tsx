import { usePage } from '@inertiajs/react';

/** Shown everywhere while payments run in test mode, so test charges are never mistaken for real ones. */
export function PaymentsTestBanner() {
    const { paymentsTestMode } = usePage().props;
    if (!paymentsTestMode) {
        return null;
    }

    return (
        <div
            role="status"
            className="bg-sky-600 px-4 py-1 text-center text-xs font-medium text-white"
        >
            Payments are in test mode: no real charges.
        </div>
    );
}
