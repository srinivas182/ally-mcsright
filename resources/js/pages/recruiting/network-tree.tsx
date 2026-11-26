import { Head, Link } from '@inertiajs/react';

import {
    NetworkBranch,
    type NetworkNode,
} from '@/components/recruiting/network-branch';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Props = { tree: NetworkNode[]; direct: number; network: number };

/** AG1b: My commissions › Network tree: who the agent brought in, and who they brought in, level by level. */
export default function NetworkTree({ tree, direct, network }: Props) {
    return (
        <>
            <Head title="Network tree" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Network tree"
                    description={
                        network === 0
                            ? 'Agents you bring in show here, and the agents they bring in below them.'
                            : `You brought in ${direct} ${direct === 1 ? 'agent' : 'agents'} directly; ${network} in your whole network. Level 1 is the agents you brought in, level 2 the agents they brought in, and so on.`
                    }
                />
                <section
                    aria-label="Your network"
                    className="rounded-xl border bg-card p-4"
                >
                    {tree.length === 0 ? (
                        <div className="grid justify-items-start gap-3 p-2">
                            <p>
                                Nobody yet. Share your link: agents who join
                                through it are placed under you.
                            </p>
                            <Button asChild>
                                <Link href="/my-network">Get my link</Link>
                            </Button>
                        </div>
                    ) : (
                        <ul className="grid gap-0.5">
                            {tree.map((n) => (
                                <NetworkBranch key={n.ulid} node={n} />
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

NetworkTree.layout = {
    breadcrumbs: [{ title: 'Network tree', href: '/my-network/tree' }],
};
