import { Head } from '@inertiajs/react';

import { Button } from '@/components/ui/button';

type Props = {
    client: string;
    returns_to: string;
    scopes: { id: string; description: string }[];
    auth_token: string;
    csrf: string;
    allowed: boolean;
};

/**
 * The plain "Allow" screen for connecting an AI assistant (specs/connect/03). A real form post (not a script
 * request), because approving sends the browser back to the AI app.
 */
export default function McpConsent({
    client,
    returns_to,
    scopes,
    auth_token,
    csrf,
    allowed,
}: Props) {
    return (
        <>
            <Head title="Connect your AI assistant" />
            <div className="mx-auto grid max-w-md gap-5 p-6 text-base">
                <h1 className="text-xl font-semibold">
                    {client} wants to connect to your RightAlly account
                </h1>
                {!allowed ? (
                    <p>
                        Your brokerage has switched off AI assistant access, so
                        it can't connect.
                    </p>
                ) : (
                    <>
                        <div className="grid gap-2">
                            <p>If you allow it, {client} will be able to:</p>
                            <ul className="grid list-disc gap-1 pl-6">
                                {scopes.map((s) => (
                                    <li key={s.id}>{s.description}</li>
                                ))}
                            </ul>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            It sees and changes only what you can in RightAlly,
                            as you. It can't delete anything, send money or text
                            people. You can disconnect it any time in Apps → AI
                            assistants. You'll go back to{' '}
                            <strong>{returns_to}</strong>.
                        </p>
                        <div className="flex gap-3">
                            <form method="post" action="/oauth/authorize">
                                <input
                                    type="hidden"
                                    name="_token"
                                    value={csrf}
                                />
                                <input
                                    type="hidden"
                                    name="auth_token"
                                    value={auth_token}
                                />
                                <Button
                                    size="lg"
                                    className="h-12 px-6 text-base"
                                >
                                    Allow
                                </Button>
                            </form>
                            <form method="post" action="/oauth/authorize">
                                <input
                                    type="hidden"
                                    name="_token"
                                    value={csrf}
                                />
                                <input
                                    type="hidden"
                                    name="_method"
                                    value="DELETE"
                                />
                                <input
                                    type="hidden"
                                    name="auth_token"
                                    value={auth_token}
                                />
                                <Button
                                    size="lg"
                                    variant="outline"
                                    className="h-12 px-6 text-base"
                                >
                                    No thanks
                                </Button>
                            </form>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}
