import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Pack = {
    place: string;
    month: string;
    stats: Record<string, unknown>;
    posts: { en: string[]; es: string[] };
    newsletter: {
        en: { subject: string; body: string };
        es: { subject: string; body: string };
    };
};
type Props = {
    places: { slug: string; name: string }[];
    place: string | null;
    pack: Pack | null;
};

/** MK2: this month's content pack: real market numbers as ready posts and a newsletter, in English and Spanish. */
export default function Content({ places, place, pack }: Props) {
    const [lang, setLang] = useState<'en' | 'es'>('en');
    const [copied, setCopied] = useState<string | null>(null);
    const copy = (key: string, value: string) => {
        void navigator.clipboard?.writeText(value).then(() => setCopied(key));
    };

    return (
        <>
            <Head title="Monthly content" />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Monthly content"
                    description="This month's real numbers for your area, already written up. Copy a post to social media, or the newsletter into an email."
                />
                <div className="flex flex-wrap items-end gap-3">
                    <label className="grid gap-1">
                        Area
                        <select
                            className="rounded-md border bg-background p-2 text-base"
                            value={place ?? ''}
                            onChange={(e) =>
                                router.get('/outreach/content', {
                                    place: e.target.value,
                                })
                            }
                        >
                            {places.map((p) => (
                                <option key={p.slug} value={p.slug}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div
                        className="flex gap-1"
                        role="group"
                        aria-label="Language"
                    >
                        <Button
                            variant={lang === 'en' ? 'default' : 'outline'}
                            onClick={() => setLang('en')}
                        >
                            English
                        </Button>
                        <Button
                            variant={lang === 'es' ? 'default' : 'outline'}
                            onClick={() => setLang('es')}
                        >
                            Español
                        </Button>
                    </div>
                </div>
                {!pack && (
                    <p className="rounded-lg border p-4 text-muted-foreground">
                        {places.length === 0
                            ? 'Content packs need listings from your MLS feed. Once it is connected, your areas appear here.'
                            : 'Not enough homes for sale in this area to publish numbers. Pick another area.'}
                    </p>
                )}
                {pack && (
                    <>
                        <section className="grid gap-3">
                            <h2 className="font-semibold">
                                Social posts for {pack.month}
                            </h2>
                            {pack.posts[lang].map((p, i) => (
                                <div
                                    key={i}
                                    className="grid gap-2 rounded-lg border p-4"
                                >
                                    <p>{p}</p>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="w-fit"
                                        onClick={() => copy(`post-${i}`, p)}
                                    >
                                        {copied === `post-${i}`
                                            ? 'Copied'
                                            : 'Copy post'}
                                    </Button>
                                </div>
                            ))}
                        </section>
                        <section className="grid gap-2 rounded-lg border p-4">
                            <h2 className="font-semibold">Newsletter</h2>
                            <p className="font-medium">
                                {pack.newsletter[lang].subject}
                            </p>
                            <pre className="font-sans whitespace-pre-wrap">
                                {pack.newsletter[lang].body}
                            </pre>
                            <Button
                                size="sm"
                                variant="outline"
                                className="w-fit"
                                onClick={() =>
                                    copy(
                                        'news',
                                        `${pack.newsletter[lang].subject}\n\n${pack.newsletter[lang].body}`,
                                    )
                                }
                            >
                                {copied === 'news'
                                    ? 'Copied'
                                    : 'Copy newsletter'}
                            </Button>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}
