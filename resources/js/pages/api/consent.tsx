import { Head } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

/** The "Allow" screen for a partner app (API1). A real form post to the OAuth server; no script needed. */
export default function PartnerConsent({
    app,
    scopes,
    allowed,
    returns_to,
    auth_token,
    csrf,
}: {
    app: {
        name: string;
        company: string;
        website: string;
        description: string;
        verified: boolean;
    } | null;
    scopes: string[];
    allowed: boolean;
    returns_to: string;
    auth_token: string;
    csrf: string;
}) {
    return (
        <>
            <Head title="Connect an app" />
            <div className="mx-auto grid w-full max-w-xl gap-5 p-6 text-lg leading-relaxed">
                {app === null ? (
                    <p>This app is not registered with RightAlly.</p>
                ) : (
                    <>
                        <h1 className="text-2xl font-semibold">
                            {app.name} wants to connect to RightAlly
                        </h1>
                        <p className="flex flex-wrap items-center gap-2 text-base">
                            by {app.company} ·{' '}
                            <a className="underline" href={app.website}>
                                {app.website}
                            </a>
                            {app.verified && (
                                <Badge>Verified by RightAlly</Badge>
                            )}
                        </p>
                        <p className="text-base">{app.description}</p>
                        <div>
                            <p className="font-medium">It will be able to:</p>
                            <ul className="list-disc pl-6">
                                {scopes.map((s) => (
                                    <li key={s}>{s}</li>
                                ))}
                            </ul>
                            <p className="mt-2 text-base text-muted-foreground">
                                It acts as you and sees only what you see. It
                                can never delete, message many people, move
                                money or see bank details. You can disconnect it
                                any time. You'll go back to{' '}
                                <strong>{returns_to}</strong>.
                            </p>
                        </div>
                        {!allowed ? (
                            <p
                                role="alert"
                                className="rounded border border-amber-300 bg-amber-50 p-3 text-base text-amber-900"
                            >
                                Your brokerage has not allowed this app yet. Ask
                                your broker to allow it in Settings › API and
                                partner apps.
                            </p>
                        ) : (
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
                        )}
                    </>
                )}
            </div>
        </>
    );
}
