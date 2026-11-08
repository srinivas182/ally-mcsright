import { Head, Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';

export default function InvitationInvalid({
    tenantName,
}: {
    tenantName: string;
}) {
    return (
        <>
            <Head title="Invitation not valid" />
            <div className="flex flex-col gap-6 text-center">
                <p className="text-sm text-muted-foreground">
                    This invitation link has expired, was replaced by a newer
                    one, or has already been used. Please ask {tenantName} to
                    send you a new invitation.
                </p>
                <Button variant="outline" asChild>
                    <Link href="/login">Go to sign in</Link>
                </Button>
            </div>
        </>
    );
}

InvitationInvalid.layout = {
    title: 'This link no longer works',
    description: 'Invitation links work once and expire after 7 days',
};
