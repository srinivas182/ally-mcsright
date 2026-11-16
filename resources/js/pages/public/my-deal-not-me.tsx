import { Head, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';

type Props = {
    t: Record<string, string>;
    locale: string;
    valid: boolean;
    done: boolean;
};

/** "This was not me" from the new sign-in email: confirm to sign out every device and tell the agent. */
export default function MyDealNotMe({ t, locale, valid, done }: Props) {
    return (
        <>
            <Head title={t.notme_title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main
                lang={locale}
                className="mx-auto grid max-w-md gap-4 p-6 text-sm"
            >
                <h1 className="text-xl font-semibold">{t.notme_title}</h1>
                {done ? (
                    <p role="status">{t.notme_done}</p>
                ) : !valid ? (
                    <p>{t.signin_expired}</p>
                ) : (
                    <>
                        <p>{t.notme_intro}</p>
                        <Button
                            variant="destructive"
                            className="justify-self-start"
                            onClick={() =>
                                router.post(window.location.pathname)
                            }
                        >
                            {t.notme_confirm}
                        </Button>
                    </>
                )}
                <p className="rounded-md bg-muted p-3 text-xs" role="note">
                    {t.fraud}
                </p>
            </main>
        </>
    );
}
