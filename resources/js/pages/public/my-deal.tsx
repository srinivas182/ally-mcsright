import { Head, Link, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    t: Record<string, string>;
    locales: Record<string, string>;
    locale: string;
    deals: { ulid: string; street: string }[];
    sent: boolean;
    expired: boolean;
};

/** Sign in to the deal portal with an emailed link (specs/ai/02, DQ3). */
export default function MyDeal({
    t,
    locales,
    locale,
    deals,
    sent,
    expired,
}: Props) {
    const form = useForm({ email: '', lang: locale });

    return (
        <>
            <Head title={t.signin_title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main
                lang={locale}
                className="mx-auto grid max-w-lg gap-4 p-6 text-sm"
            >
                <nav className="flex justify-end gap-3" aria-label={t.language}>
                    {Object.entries(locales).map(([k, l]) => (
                        <a
                            key={k}
                            href={`/my-deal?lang=${k}`}
                            hrefLang={k}
                            aria-current={k === locale ? 'page' : undefined}
                            className={
                                k === locale ? 'font-semibold' : 'underline'
                            }
                        >
                            {l}
                        </a>
                    ))}
                </nav>
                <h1 className="text-xl font-semibold">{t.signin_title}</h1>
                {deals.length > 1 && (
                    <section className="grid gap-2">
                        <h2 className="font-medium">{t.pick_deal}</h2>
                        {deals.map((d) => (
                            <Link
                                key={d.ulid}
                                href={`/my-deal/${d.ulid}`}
                                className="rounded-md border p-3 underline"
                            >
                                {d.street}
                            </Link>
                        ))}
                    </section>
                )}
                {expired && (
                    <p role="alert" className="text-destructive">
                        {t.signin_expired}
                    </p>
                )}
                {sent ? (
                    <p role="status" className="rounded-md border p-3">
                        {t.signin_sent}
                    </p>
                ) : (
                    <form
                        className="grid gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/my-deal/link');
                        }}
                    >
                        <p>{t.signin_intro}</p>
                        <label className="grid gap-1">
                            <span>{t.signin_email}</span>
                            <Input
                                type="email"
                                autoComplete="email"
                                required
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                            />
                        </label>
                        <Button
                            type="submit"
                            className="justify-self-start"
                            disabled={form.processing}
                        >
                            {t.signin_send}
                        </Button>
                    </form>
                )}
                <p className="rounded-md bg-muted p-3 text-xs" role="note">
                    {t.fraud}
                </p>
            </main>
        </>
    );
}
