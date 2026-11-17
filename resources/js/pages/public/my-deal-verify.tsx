import { Head, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    t: Record<string, string>;
    locale: string;
    token: string;
    street: string;
    sms: string | null;
    sentTo: string | null;
    error: string | null;
};

/** Turns the reason the server gave into words in the client's language. */
function message(
    t: Record<string, string>,
    error: string | null,
): string | null {
    if (!error) {
        return null;
    }
    if (error.startsWith('code_wrong:')) {
        return t.code_wrong.replace(':left', error.split(':')[1]);
    }
    return (
        { wrong_email: t.verify_wrong_email, code_expired: t.code_expired }[
            error
        ] ?? t.signin_expired
    );
}

/**
 * Sign-in step two and three (specs/ai/02, DQ3): confirm the invited address, then type the 6-digit code.
 * Opening this page changes nothing, so email security scanners cannot use the link up.
 */
export default function MyDealVerify({
    t,
    locale,
    token,
    street,
    sms,
    sentTo,
    error,
}: Props) {
    const ask = useForm({ email: '', via: 'email' });
    const code = useForm({ code: '', remember: false });
    const base = `/my-deal/in/${token}`;
    const problem = message(t, error);
    const send = (via: 'email' | 'sms') => {
        ask.transform((d) => ({ ...d, via }));
        ask.post(`${base}/code`);
    };

    return (
        <>
            <Head title={t.verify_title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main
                lang={locale}
                className="mx-auto grid max-w-md gap-4 p-6 text-sm"
            >
                <p className="text-muted-foreground">{street}</p>
                <h1 className="text-xl font-semibold">
                    {sentTo ? t.code_title : t.verify_title}
                </h1>
                {problem && (
                    <p role="alert" className="text-destructive">
                        {problem}
                    </p>
                )}
                {sentTo ? (
                    <form
                        className="grid gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            code.post(`${base}/verify`);
                        }}
                    >
                        <p>{t.code_intro.replace(':where', sentTo)}</p>
                        <label className="grid gap-1">
                            <span>{t.code_label}</span>
                            <Input
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                pattern="[0-9 ]{6,7}"
                                maxLength={7}
                                required
                                value={code.data.code}
                                onChange={(e) =>
                                    code.setData('code', e.target.value)
                                }
                            />
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={code.data.remember}
                                onChange={(e) =>
                                    code.setData('remember', e.target.checked)
                                }
                            />
                            {t.code_remember}
                        </label>
                        <span className="flex flex-wrap gap-2">
                            <Button type="submit" disabled={code.processing}>
                                {t.code_submit}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => (window.location.href = base)}
                            >
                                {t.code_again}
                            </Button>
                        </span>
                    </form>
                ) : (
                    <form
                        className="grid gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            send('email');
                        }}
                    >
                        <p>{t.verify_intro}</p>
                        <label className="grid gap-1">
                            <span>{t.verify_email}</span>
                            <Input
                                type="email"
                                autoComplete="email"
                                required
                                value={ask.data.email}
                                onChange={(e) =>
                                    ask.setData('email', e.target.value)
                                }
                            />
                        </label>
                        <span className="flex flex-wrap gap-2">
                            <Button type="submit" disabled={ask.processing}>
                                {t.verify_send_email}
                            </Button>
                            {sms && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        ask.processing ||
                                        ask.data.email.trim() === ''
                                    }
                                    onClick={() => send('sms')}
                                >
                                    {t.verify_send_sms.replace(':phone', sms)}
                                </Button>
                            )}
                        </span>
                    </form>
                )}
                <p className="rounded-md bg-muted p-3 text-xs" role="note">
                    {t.fraud}
                </p>
            </main>
        </>
    );
}
