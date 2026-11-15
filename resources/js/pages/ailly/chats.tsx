import { Head, usePoll } from '@inertiajs/react';

import { LiveChats, type LiveChat } from '@/components/ailly/live-chats';
import { PageHeader } from '@/components/ui/page-header';

/** Live website chats the user can join (refreshes every 10 seconds). */
export default function AillyChats({ live }: { live: LiveChat[] }) {
    usePoll(10_000, { only: ['live'] });

    return (
        <>
            <Head title="Live website chats" />
            <div className="grid max-w-3xl gap-4 p-4">
                <PageHeader
                    title="Live website chats"
                    description="Visitors chatting with the website assistant now. Join one to talk to them yourself; the assistant stays quiet until you hand it back."
                />
                <LiveChats live={live} />
            </div>
        </>
    );
}

AillyChats.layout = {
    breadcrumbs: [{ title: 'Live website chats', href: '/ailly/chats' }],
};
