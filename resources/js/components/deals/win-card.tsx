import { router, useForm } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';

export type WinCard = {
    website: boolean;
    suggested: string;
    card: {
        status: string;
        message: string;
        show_price: boolean;
        views: number;
        url: string | null;
    } | null;
};

/**
 * "Just sold" / "Just closed" (RF1, specs/transactions/32): a page on the brokerage website for a closed deal, shared
 * in one tap. Only after the agent ticks that the client agreed. Never shows the client's name; price only if chosen.
 */
export function WinCardPanel({ ulid, win }: { ulid: string; win: WinCard }) {
    const live = win.card?.status === 'live';
    const form = useForm({
        client_agreed: false,
        message: win.card?.message ?? win.suggested,
        show_price: win.card?.show_price ?? false,
    });
    const url = live ? win.card?.url : null;

    return (
        <section
            className="flex flex-col gap-3 rounded-lg border p-4"
            aria-labelledby="win-card-title"
        >
            <h2 id="win-card-title" className="text-base font-semibold">
                Share this win
            </h2>
            {!win.website ? (
                <p className="text-sm text-muted-foreground">
                    Publish your brokerage website first (Settings › Website).
                    Win cards live there.
                </p>
            ) : (
                <>
                    {url && (
                        <div className="flex flex-col gap-2 text-sm">
                            <p>
                                Your win card is live ({win.card?.views ?? 0}{' '}
                                views):{' '}
                                <a
                                    className="underline"
                                    href={url}
                                    target="_blank"
                                    rel="noopener"
                                >
                                    {url}
                                </a>
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button asChild size="sm" variant="outline">
                                    <a
                                        href={`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        Share on Facebook
                                    </a>
                                </Button>
                                <Button asChild size="sm" variant="outline">
                                    <a
                                        href={`https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(url)}`}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        Share on LinkedIn
                                    </a>
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        void navigator.clipboard?.writeText(url)
                                    }
                                >
                                    Copy link
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        router.delete(
                                            `/deals/${ulid}/win-card`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Hide it
                                </Button>
                            </div>
                        </div>
                    )}
                    <form
                        className="flex flex-col gap-2 text-sm"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/deals/${ulid}/win-card`, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <label className="flex flex-col gap-1">
                            Message
                            <textarea
                                className="min-h-20 rounded-md border bg-transparent p-2"
                                maxLength={400}
                                value={form.data.message}
                                onChange={(e) =>
                                    form.setData('message', e.target.value)
                                }
                            />
                        </label>
                        <InputError message={form.errors.message} />
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.show_price}
                                onChange={(e) =>
                                    form.setData('show_price', e.target.checked)
                                }
                            />
                            Show the price
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.client_agreed}
                                onChange={(e) =>
                                    form.setData(
                                        'client_agreed',
                                        e.target.checked,
                                    )
                                }
                            />
                            My client agreed to me sharing this. (Their name is
                            never shown.)
                        </label>
                        <InputError message={form.errors.client_agreed} />
                        <InputError
                            message={
                                (form.errors as Record<string, string>).win
                            }
                        />
                        <div>
                            <Button type="submit" disabled={form.processing}>
                                {url
                                    ? 'Update the win card'
                                    : 'Make a win card'}
                            </Button>
                        </div>
                    </form>
                </>
            )}
        </section>
    );
}
