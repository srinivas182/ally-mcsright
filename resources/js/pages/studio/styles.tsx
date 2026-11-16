import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Style = {
    key: string;
    name: string;
    family: string;
    about: string;
    dark: boolean;
    finish: string;
    preview: string | null;
    allowed: boolean;
};

/**
 * Ally Studio → Styles (specs/grow/13, SA1d): every style shown with your own listing and brand. Choose your
 * style and finish, heart the ones Autopilot may rotate through, or let style match suggest the best for you.
 */
export default function StudioStyles({
    styles,
    families,
    finishes,
    current,
    locked,
    match,
}: {
    styles: Style[];
    families: Record<string, string>;
    finishes: Record<string, string>;
    current: { style: string; finish: string | null; favorites: string[] };
    locked: boolean;
    match: string[];
}) {
    const form = useForm({
        style: current.style,
        finish: current.finish ?? '',
        favorites: current.favorites,
    });
    const making = styles.some((s) => s.allowed && s.preview === null);

    useEffect(() => {
        if (!making) {
            return;
        }
        const t = setInterval(() => router.reload({ only: ['styles'] }), 4000);

        return () => clearInterval(t);
    }, [making]);

    const heart = (key: string) =>
        form.setData(
            'favorites',
            form.data.favorites.includes(key)
                ? form.data.favorites.filter((k) => k !== key)
                : [...form.data.favorites, key].slice(0, 6),
        );

    return (
        <>
            <Head title="Ally Studio styles" />
            <div className="grid gap-6 p-4 text-base">
                <PageHeader
                    title="Styles"
                    description="Every style, shown with your own listing and brand. Choose yours, and heart the ones Autopilot may rotate through."
                />

                <section className="grid gap-3 rounded-lg border p-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="mr-2 font-medium">Finish</span>
                        <Button
                            size="sm"
                            variant={
                                form.data.finish === '' ? 'default' : 'outline'
                            }
                            onClick={() => form.setData('finish', '')}
                        >
                            The style's own
                        </Button>
                        {Object.entries(finishes).map(([k, l]) => (
                            <Button
                                key={k}
                                size="sm"
                                variant={
                                    form.data.finish === k
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={() => form.setData('finish', k)}
                            >
                                {l}
                            </Button>
                        ))}
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            onClick={() =>
                                form.post('/studio/styles', {
                                    preserveScroll: true,
                                })
                            }
                            disabled={form.processing}
                        >
                            Save my style
                        </Button>
                        <Button
                            variant="outline"
                            disabled={form.data.favorites.length === 0}
                            onClick={() =>
                                router.post('/studio/styles/match', {
                                    liked: form.data.favorites,
                                })
                            }
                        >
                            Find my best styles
                        </Button>
                        <span className="text-sm text-muted-foreground">
                            {form.data.favorites.length} hearted · Autopilot
                            rotates through them
                        </span>
                        <Link
                            href="/studio/create"
                            className="ml-auto text-sm underline"
                        >
                            Create a design
                        </Link>
                    </div>
                    {match.length > 0 && (
                        <p>
                            Your best styles:{' '}
                            <strong>
                                {match
                                    .map(
                                        (k) =>
                                            styles.find((s) => s.key === k)
                                                ?.name,
                                    )
                                    .join(', ')}
                            </strong>
                        </p>
                    )}
                    {locked && (
                        <p className="text-sm text-muted-foreground">
                            Your brokerage chooses which styles its agents use.
                        </p>
                    )}
                </section>

                {Object.entries(families).map(([fam, label]) => (
                    <section key={fam} className="grid gap-3">
                        <h2 className="font-medium">{label}</h2>
                        <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4">
                            {styles
                                .filter((s) => s.family === fam)
                                .map((s) => (
                                    <div
                                        key={s.key}
                                        className={`grid content-start gap-2 rounded-xl border p-2 ${form.data.style === s.key ? 'border-primary ring-2 ring-primary' : ''} ${s.allowed ? '' : 'opacity-40'}`}
                                    >
                                        {s.preview ? (
                                            <img
                                                src={s.preview}
                                                alt={`${s.name} style`}
                                                className="aspect-[4/5] w-full rounded-lg object-cover"
                                                loading="lazy"
                                            />
                                        ) : (
                                            <div className="grid aspect-[4/5] place-items-center rounded-lg bg-muted text-sm">
                                                Designing your preview…
                                            </div>
                                        )}
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <div className="font-medium">
                                                    {s.name}{' '}
                                                    {match.includes(s.key) && (
                                                        <Badge>for you</Badge>
                                                    )}
                                                </div>
                                                <div className="text-sm text-muted-foreground">
                                                    {s.about}
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                aria-label={`Heart ${s.name}`}
                                                aria-pressed={form.data.favorites.includes(
                                                    s.key,
                                                )}
                                                className="text-2xl leading-none"
                                                disabled={!s.allowed}
                                                onClick={() => heart(s.key)}
                                            >
                                                {form.data.favorites.includes(
                                                    s.key,
                                                )
                                                    ? '♥'
                                                    : '♡'}
                                            </button>
                                        </div>
                                        <Button
                                            size="sm"
                                            variant={
                                                form.data.style === s.key
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            disabled={!s.allowed}
                                            onClick={() =>
                                                form.setData('style', s.key)
                                            }
                                        >
                                            {form.data.style === s.key
                                                ? 'My style'
                                                : 'Use this style'}
                                        </Button>
                                    </div>
                                ))}
                        </div>
                    </section>
                ))}
            </div>
        </>
    );
}
