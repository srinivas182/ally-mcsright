import { router } from '@inertiajs/react';

import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';

const labels: Record<string, string> = {
    google: 'Google',
    microsoft: 'Microsoft',
};

export default function ConnectedAccounts({
    providers,
    identities,
}: {
    providers: string[];
    identities: { provider: string; email: string | null }[];
}) {
    if (providers.length === 0) {
        return null;
    }

    return (
        <div className="space-y-4">
            <Heading
                variant="small"
                title="Connected accounts"
                description="Sign in with Google or Microsoft. Two-step verification still applies."
            />
            <ul className="divide-y rounded-lg border">
                {providers.map((p) => {
                    const linked = identities.find((i) => i.provider === p);
                    return (
                        <li
                            key={p}
                            className="flex items-center justify-between gap-2 p-3 text-sm"
                        >
                            <span>
                                <span className="font-medium">
                                    {labels[p] ?? p}
                                </span>
                                {linked?.email && (
                                    <span className="block text-muted-foreground">
                                        {linked.email}
                                    </span>
                                )}
                            </span>
                            {linked ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.delete(
                                            `/settings/identities/${p}`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Disconnect
                                </Button>
                            ) : (
                                <Button size="sm" asChild>
                                    <a href={`/auth/${p}/start?intent=link`}>
                                        Connect
                                    </a>
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
