import { Head } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';

import { Button } from '@/components/ui/button';

/** CM4: after joining. The account opens as soon as the payment is confirmed (usually within a minute). */
export default function JoinDone({ brokerage }: { brokerage: string }) {
    return (
        <>
            <Head title={`Welcome to ${brokerage}`}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main className="grid min-h-screen place-items-center bg-muted/40 px-4">
                <div className="grid max-w-md gap-4 rounded-2xl border bg-card p-8 text-center shadow-sm">
                    <CheckCircle2
                        className="mx-auto size-12 text-emerald-600"
                        aria-hidden="true"
                    />
                    <h1 className="text-2xl font-bold">
                        Welcome to {brokerage}!
                    </h1>
                    <p className="text-muted-foreground">
                        Your licence checked out. As soon as your payment is
                        confirmed (usually within a minute), your account opens.
                        Sign in with the email and password you just chose.
                    </p>
                    <Button asChild size="lg">
                        <a href="/login">Sign in</a>
                    </Button>
                </div>
            </main>
        </>
    );
}
