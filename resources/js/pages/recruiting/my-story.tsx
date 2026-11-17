import { Head, router, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

/** An agent's own words for the brokerage's careers page (RC2), only with their consent, removable any time. */
export default function MyStory({
    story,
    live,
    url,
}: {
    story: { quote: string; show_deals: boolean; since: string } | null;
    live: boolean;
    url: string | null;
}) {
    const form = useForm({
        quote: story?.quote ?? '',
        show_deals: story?.show_deals ?? false,
        consent: false,
    });

    return (
        <>
            <Head title="My words for the careers page" />
            <div className="grid max-w-2xl gap-5 p-4 text-base">
                <PageHeader
                    title="My words for the careers page"
                    description="Agents thinking of joining like to hear from agents. If you'd like to, say in a sentence or two what it's like working here."
                />
                {story && (
                    <p className="rounded border p-3 text-sm">
                        On the careers page since {story.since}
                        {live && url ? (
                            <>
                                {' '}
                                (
                                <a
                                    className="underline"
                                    href={url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    see it
                                </a>
                                )
                            </>
                        ) : (
                            ' (the page is not live yet)'
                        )}
                        .
                    </p>
                )}
                <form
                    className="grid gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/recruiting/my-story', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="grid gap-1">
                        In your own words
                        <Textarea
                            rows={4}
                            maxLength={600}
                            value={form.data.quote}
                            onChange={(e) =>
                                form.setData('quote', e.target.value)
                            }
                        />
                    </label>
                    <label className="flex items-start gap-2">
                        <input
                            type="checkbox"
                            className="mt-1 size-5"
                            checked={form.data.show_deals}
                            onChange={(e) =>
                                form.setData('show_deals', e.target.checked)
                            }
                        />
                        Also show how many closings I've had in the last 12
                        months
                    </label>
                    <label className="flex items-start gap-2">
                        <input
                            type="checkbox"
                            className="mt-1 size-5"
                            checked={form.data.consent}
                            onChange={(e) =>
                                form.setData('consent', e.target.checked)
                            }
                        />
                        I agree to these words and my name being shown on the
                        brokerage's public careers page. I can take them down
                        any time.
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            disabled={form.processing || !form.data.consent}
                        >
                            {story ? 'Update' : 'Add my words'}
                        </Button>
                        {story && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.delete('/recruiting/my-story', {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                Take them down
                            </Button>
                        )}
                    </div>
                    {Object.values(form.errors).map((m) => (
                        <p
                            key={m}
                            role="alert"
                            className="text-sm text-red-700"
                        >
                            {m}
                        </p>
                    ))}
                </form>
            </div>
        </>
    );
}
