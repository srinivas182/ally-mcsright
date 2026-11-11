import { Head, Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';

export default function AccountSecured() {
    return (
        <>
            <Head title="Account secured" />
            <div className="flex flex-col gap-6 text-center">
                <p className="text-sm text-muted-foreground">
                    We signed your account out everywhere and emailed you a link
                    to choose a new password. If you did not expect this, tell
                    your broker.
                </p>
                <Button variant="outline" asChild>
                    <Link href="/login">Go to sign in</Link>
                </Button>
            </div>
        </>
    );
}

AccountSecured.layout = {
    title: 'Your account is secured',
    description: 'Every device has been signed out',
};
