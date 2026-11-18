import { Head, Link, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    eligible: number;
    sample: { ulid: string; name: string }[];
    daily: number;
    wording: string;
};

/**
 * CN1 (specs/grow/18): ask everyone in your list who has a mobile number but no texting consent, by email, to agree
 * in one tap. Their tap is written consent, so automated and AI texts become allowed for them.
 */
export default function TextingConsent({
    eligible,
    sample,
    daily,
    wording,
}: Props) {
    return (
        <>
            <Head title="Texting consent" />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Get texting consent in one tap"
                    description="Texting someone needs their consent; automated and AI texts need it in writing. Send an email they can answer with one tap. A yes is recorded as written consent with full proof."
                />
                <div className="grid gap-3 rounded-lg border p-4">
                    <p className="text-lg">
                        <strong>{eligible.toLocaleString()}</strong>{' '}
                        {eligible === 1 ? 'person' : 'people'} in your list can
                        be asked now.
                    </p>
                    {sample.length > 0 && (
                        <p className="text-muted-foreground">
                            For example:{' '}
                            {sample.map((c, i) => (
                                <span key={c.ulid}>
                                    {i > 0 && ', '}
                                    <Link
                                        href={`/contacts/${c.ulid}`}
                                        className="underline"
                                    >
                                        {c.name}
                                    </Link>
                                </span>
                            ))}
                        </p>
                    )}
                    <p className="text-sm text-muted-foreground">
                        Not asked: people without a mobile number or email,
                        people who unsubscribed or replied STOP recently, people
                        who already agreed in writing, and anyone asked in the
                        last 30 days. Up to {daily} emails a day for the whole
                        brokerage.
                    </p>
                    <Button
                        className="justify-self-start"
                        disabled={eligible === 0}
                        onClick={() =>
                            router.post(
                                '/contacts/texting-consent',
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Send the one-tap email to{' '}
                        {Math.min(eligible, daily).toLocaleString()}
                    </Button>
                </div>
                <section className="grid gap-2">
                    <h2 className="font-semibold">
                        What they see and agree to
                    </h2>
                    <p className="rounded-lg border p-4 text-muted-foreground">
                        {wording}
                    </p>
                </section>
            </div>
        </>
    );
}
