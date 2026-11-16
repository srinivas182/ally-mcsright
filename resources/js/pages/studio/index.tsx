import { Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { Progress } from '@/components/ui/progress';

type Item = 'post' | 'reel' | 'tour' | 'enhance' | 'voice';

type Allowances = {
    posts: number;
    reels: number;
    tours: number;
    enhance: number;
    voice: number;
};

type Kit = {
    collection: string;
    colors: Record<string, string>;
    fonts: { heading: string; body: string };
    logo: string | null;
    headshot: string | null;
    cutout: string | null;
    details: Record<string, string>;
    brokerage: string;
};

/**
 * Grow → Ally Studio (specs/grow/13): the package and this month's use, the brand kit at a glance, what is missing,
 * and where posts go. Designs, the calendar and Autopilot arrive in the next Studio sprints.
 */
export default function StudioIndex({
    plan,
    usage,
    items,
    offers,
    missing,
    kit,
    collections,
    social,
}: {
    plan: {
        package: string;
        name: string;
        period: string;
        renews: string | null;
    } | null;
    usage: Record<Item, { used: number; extra: number; allowance: number }>;
    items: Record<Item, string>;
    offers: {
        key: string;
        name: string;
        summary: string;
        cents: number;
        allowances: Allowances | null;
        features: string[];
    }[];
    missing: string[];
    kit: Kit;
    collections: Record<string, string>;
    social: { key: string; name: string; ready: boolean; connected: boolean }[];
}) {
    const photo = kit.cutout ?? kit.headshot;

    return (
        <>
            <Head title="Ally Studio" />
            <div className="grid max-w-4xl gap-6 p-4 text-base">
                <PageHeader
                    title="Ally Studio"
                    description="Your AI social team: designed posts with your photo and brand, Reels and Shorts, a calendar and Autopilot."
                />

                <section
                    className="grid gap-4 rounded-xl p-6 sm:grid-cols-[auto_1fr]"
                    style={{
                        background: kit.colors.primary,
                        color: kit.colors.paper,
                    }}
                >
                    {photo ? (
                        <img
                            src={photo}
                            alt={kit.details.display_name ?? 'Your headshot'}
                            className="size-28 rounded-full object-cover"
                            style={{ background: kit.colors.accent }}
                        />
                    ) : (
                        <div
                            className="grid size-28 place-items-center rounded-full text-sm"
                            style={{ background: kit.colors.accent }}
                        >
                            Add your photo
                        </div>
                    )}
                    <div className="grid content-center gap-1">
                        <p
                            className="text-2xl"
                            style={{ fontFamily: kit.fonts.heading }}
                        >
                            {kit.details.display_name}
                        </p>
                        <p style={{ color: kit.colors.accent }}>
                            {kit.details.title ?? kit.brokerage}
                        </p>
                        <p className="text-sm opacity-80">
                            {collections[kit.collection]} collection
                        </p>
                    </div>
                </section>

                {plan && (
                    <div className="flex flex-wrap gap-3">
                        <Button asChild size="lg">
                            <Link href="/studio/create">Create a design</Link>
                        </Button>
                        <Button asChild size="lg" variant="outline">
                            <Link href="/studio/styles">Styles</Link>
                        </Button>
                        <Button asChild size="lg" variant="outline">
                            <Link href="/studio/photos">Enhance photos</Link>
                        </Button>
                        <Button asChild size="lg" variant="outline">
                            <Link href="/studio/reels">Reels</Link>
                        </Button>
                        <Button asChild size="lg" variant="outline">
                            <Link href="/studio/results">Results</Link>
                        </Button>
                        <Button asChild size="lg" variant="outline">
                            <Link href="/studio/autopilot">Autopilot</Link>
                        </Button>
                        <Button asChild size="lg" variant="outline">
                            <Link href="/studio/brand">Brand kit</Link>
                        </Button>
                    </div>
                )}

                {missing.length > 0 && (
                    <section className="grid gap-2 rounded-lg border border-primary/40 p-4">
                        <h2 className="font-medium">
                            Finish your brand kit for the best designs
                        </h2>
                        <ul className="list-disc pl-5">
                            {missing.map((m) => (
                                <li key={m}>{m}</li>
                            ))}
                        </ul>
                        <div>
                            <Button asChild>
                                <Link href="/studio/brand">Open brand kit</Link>
                            </Button>
                        </div>
                    </section>
                )}

                {plan ? (
                    <section className="grid gap-3 rounded-lg border p-4">
                        <h2 className="font-medium">
                            {plan.name}{' '}
                            {plan.renews && (
                                <Badge variant="outline">
                                    renews {plan.renews}
                                </Badge>
                            )}
                        </h2>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {(Object.keys(items) as Item[])
                                .filter(
                                    (i) =>
                                        usage[i].allowance > 0 ||
                                        usage[i].extra > 0,
                                )
                                .map((i) => (
                                    <div key={i} className="grid gap-1">
                                        <span>
                                            {items[i]}: {usage[i].used} of{' '}
                                            {usage[i].allowance}
                                            {usage[i].extra > 0 &&
                                                ` (+${usage[i].extra} with credits)`}
                                        </span>
                                        <Progress
                                            value={
                                                usage[i].allowance > 0
                                                    ? (usage[i].used * 100) /
                                                      usage[i].allowance
                                                    : 100
                                            }
                                        />
                                    </div>
                                ))}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Past your allowance, each item is paid with credits.
                        </p>
                    </section>
                ) : offers.length === 0 ? (
                    <section className="rounded-lg border p-4">
                        Ally Studio isn't on sale for your brokerage yet.
                        RightAlly support switches it on.
                    </section>
                ) : null}

                {offers.length > 0 && (
                    <section className="grid gap-3 sm:grid-cols-3">
                        {offers.map((o) => (
                            <div
                                key={o.key}
                                className={`grid content-start gap-2 rounded-xl border p-4 ${plan?.package === o.key ? 'border-primary' : ''}`}
                            >
                                <h3 className="font-medium">{o.name}</h3>
                                <p className="text-2xl">
                                    ${(o.cents / 100).toFixed(0)}
                                    <span className="text-sm text-muted-foreground">
                                        {' '}
                                        a month
                                    </span>
                                </p>
                                <p className="text-sm">{o.summary}</p>
                                {o.allowances && (
                                    <ul className="text-sm text-muted-foreground">
                                        <li>
                                            {o.allowances.posts} designed posts
                                        </li>
                                        <li>
                                            {o.allowances.reels} Reels and
                                            Shorts
                                        </li>
                                        {o.allowances.tours > 0 && (
                                            <li>
                                                {o.allowances.tours} YouTube
                                                tours
                                            </li>
                                        )}
                                        {o.features.map((f) => (
                                            <li key={f}>{f}</li>
                                        ))}
                                    </ul>
                                )}
                                {plan?.package !== o.key && (
                                    <Button asChild variant="outline">
                                        <Link href={`/apps/${o.key}`}>
                                            Choose
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        ))}
                    </section>
                )}

                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="font-medium">Where your posts go</h2>
                    <ul className="grid gap-1">
                        {social
                            .filter((s) => s.ready)
                            .map((s) => (
                                <li key={s.key}>
                                    {s.name}:{' '}
                                    {s.connected ? (
                                        'connected'
                                    ) : (
                                        <Link
                                            href={`/apps/${s.key}`}
                                            className="underline"
                                        >
                                            connect
                                        </Link>
                                    )}
                                </li>
                            ))}
                    </ul>
                    <div>
                        <Button asChild variant="outline">
                            <Link href="/social">Write a post</Link>
                        </Button>
                    </div>
                </section>
            </div>
        </>
    );
}
