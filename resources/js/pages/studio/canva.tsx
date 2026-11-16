import { Head, Link, router, usePoll } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

/**
 * Studio → Canva (specs/grow/13, C7d-3): getting the design ready in Canva, opening it, and bringing the finished
 * design back as a Studio design to post, advertise or download.
 */
export default function StudioCanva({
    session,
    design,
}: {
    session: {
        ulid: string;
        status: 'preparing' | 'editing' | 'importing' | 'ready' | 'failed';
        error: string | null;
        edit_url: string | null;
    };
    design: {
        ulid: string;
        listing: string | null;
        image: string | null;
        slides: number;
    } | null;
}) {
    usePoll(
        3_000,
        {},
        {
            autoStart:
                session.status === 'preparing' ||
                session.status === 'importing',
        },
    );

    return (
        <>
            <Head title="Canva" />
            <div className="grid max-w-xl gap-6 p-4 text-base">
                <PageHeader
                    title="Design in Canva"
                    description="Your logo, headshot and listing photos are in your Canva uploads. When you're done, press Return in Canva to bring the design back."
                />
                {session.status === 'preparing' && (
                    <p>Getting your design ready in Canva…</p>
                )}
                {session.status === 'editing' && session.edit_url && (
                    <div className="grid gap-3">
                        <Button asChild size="lg">
                            <a href={session.edit_url}>Open in Canva</a>
                        </Button>
                        <p className="text-sm text-muted-foreground">
                            Finished and came back another way?{' '}
                            <Link
                                href={`/studio/canva/${session.ulid}/back`}
                                className="underline"
                            >
                                Bring it back
                            </Link>
                        </p>
                    </div>
                )}
                {session.status === 'importing' && (
                    <p>Bringing your design back from Canva…</p>
                )}
                {session.status === 'failed' && (
                    <div className="grid gap-3">
                        <p className="text-destructive">
                            {session.error ??
                                'Something went wrong with Canva.'}
                        </p>
                        <div>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.get(
                                        `/studio/canva/${session.ulid}/back`,
                                    )
                                }
                            >
                                Try bringing it back again
                            </Button>
                        </div>
                    </div>
                )}
                {session.status === 'ready' && design && (
                    <div className="grid gap-3">
                        {design.image && (
                            <img
                                src={design.image}
                                alt="Your Canva design"
                                className="aspect-[4/5] w-full max-w-sm rounded-lg object-cover"
                            />
                        )}
                        <p>
                            It's in Ally Studio
                            {design.slides > 1
                                ? `, as a ${design.slides}-slide carousel`
                                : ''}
                            .
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                onClick={() =>
                                    router.post(
                                        `/studio/designs/${design.ulid}/post`,
                                    )
                                }
                            >
                                Post it
                            </Button>
                            {design.listing && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={`/ads/new?listing=${design.listing}&design=${design.ulid}`}
                                    >
                                        Advertise
                                    </Link>
                                </Button>
                            )}
                            <Button variant="outline" asChild>
                                <Link href="/studio/create">
                                    See it with your designs
                                </Link>
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}
