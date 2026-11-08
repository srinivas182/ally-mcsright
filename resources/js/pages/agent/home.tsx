import { Head } from '@inertiajs/react';

import { EmptyState } from '@/components/ui/empty-state';

type Props = { firstName: string; assisting: string | null };

export default function AgentHome({ firstName, assisting }: Props) {
    return (
        <>
            <Head title="Today" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">
                        Good to see you, {firstName}
                    </h1>
                    {assisting && (
                        <p className="text-sm text-muted-foreground">
                            You're working for {assisting}.
                        </p>
                    )}
                </header>
                <EmptyState
                    title="Your day starts here"
                    description="Your daily game plan, deals and leads will appear on this page as RightAlly's modules switch on."
                />
            </div>
        </>
    );
}

AgentHome.layout = {
    breadcrumbs: [{ title: 'Today', href: '/agent' }],
};
