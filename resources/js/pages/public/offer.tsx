import { Head } from '@inertiajs/react';

import {
    OfferForm,
    type OfferOptions,
    type OfferTerms,
} from '@/components/deals/offer-form';

type Props = {
    valid: boolean;
    sent: boolean;
    listing: {
        street: string;
        city: string | null;
        state: string;
        brokerage: string;
    } | null;
    deadline: string | null;
    offer: (OfferTerms & { label: string }) | null;
    options: OfferOptions;
};

/** Buyers' agents send or revise an offer (specs/ai/02, DQ4). No account; never indexed. */
export default function PublicOffer({
    valid,
    sent,
    listing,
    deadline,
    offer,
    options,
}: Props) {
    const title = offer ? 'Revise your offer' : 'Send an offer';

    return (
        <>
            <Head title={title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main className="mx-auto grid max-w-3xl gap-4 p-4 text-sm md:p-6">
                <h1 className="text-xl font-semibold">{title}</h1>
                {!valid || !listing ? (
                    <p>
                        This link is no longer taking offers. Please contact the
                        listing agent.
                    </p>
                ) : sent ? (
                    <p role="status" className="rounded-md border p-3">
                        Thank you. The listing agent has your offer
                        {offer ? ' changes' : ''}. We have emailed you a private
                        link to revise it while offers are being taken.
                    </p>
                ) : (
                    <>
                        <p>
                            {listing.street}
                            {listing.city ? `, ${listing.city}` : ''},{' '}
                            {listing.state} · listed with {listing.brokerage}
                        </p>
                        {deadline && (
                            <p className="rounded-md border border-amber-500 p-3">
                                Highest and best by{' '}
                                <strong>
                                    {new Date(deadline).toLocaleString()}
                                </strong>
                                . Every buyer&apos;s agent was told the same
                                deadline at the same time.
                            </p>
                        )}
                        <OfferForm
                            action={
                                typeof window !== 'undefined'
                                    ? window.location.pathname
                                    : ''
                            }
                            options={options}
                            initial={offer ?? {}}
                            withFile
                            showAgent={!offer}
                            submitLabel={
                                offer
                                    ? 'Send the revised offer'
                                    : 'Send the offer'
                            }
                        />
                        <p
                            className="rounded-md bg-muted p-3 text-xs"
                            role="note"
                        >
                            Never send earnest money on wiring instructions
                            received by email; confirm by phone with the title
                            company first.
                        </p>
                    </>
                )}
            </main>
        </>
    );
}
