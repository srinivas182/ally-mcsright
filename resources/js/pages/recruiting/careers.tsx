import { Head, Link, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Careers = {
    live: boolean;
    headline: string;
    intro: string;
    reasons: string[];
    calculator: boolean;
};

/** The careers page on the brokerage's website (RC2): what it says, the calculator, and agents' own words. */
export default function CareersSettings({
    careers,
    url,
    stories,
    canEdit,
    siteLive,
}: {
    careers: Careers;
    url: string | null;
    stories: { name: string; quote: string; deals: number | null }[];
    canEdit: boolean;
    siteLive: boolean;
}) {
    const form = useForm({ ...careers, reasons: careers.reasons.join('\n') });

    return (
        <>
            <Head title="Careers page" />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title="Careers page"
                    description="A page on your website for agents thinking of joining: why you, what they'd keep, and a form that lands in recruiting."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/recruiting">
                                    Back to recruiting
                                </Link>
                            </Button>
                            {url && (
                                <Button asChild>
                                    <a
                                        href={url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Open the page
                                    </a>
                                </Button>
                            )}
                        </div>
                    }
                />
                {!siteLive && (
                    <p className="rounded border p-3 text-sm">
                        Your website isn't live yet; the careers page goes up
                        with it.
                    </p>
                )}

                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/recruiting/careers', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <fieldset disabled={!canEdit} className="grid gap-3">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={form.data.live}
                                onChange={(e) =>
                                    form.setData('live', e.target.checked)
                                }
                            />
                            Show the careers page on our website (/careers)
                        </label>
                        <label className="grid gap-1 text-sm">
                            Headline
                            <Input
                                value={form.data.headline}
                                maxLength={90}
                                onChange={(e) =>
                                    form.setData('headline', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Introduction
                            <Textarea
                                rows={3}
                                value={form.data.intro}
                                maxLength={600}
                                onChange={(e) =>
                                    form.setData('intro', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Why agents join (one per line)
                            <Textarea
                                rows={4}
                                value={form.data.reasons}
                                onChange={(e) =>
                                    form.setData('reasons', e.target.value)
                                }
                            />
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={form.data.calculator}
                                onChange={(e) =>
                                    form.setData('calculator', e.target.checked)
                                }
                            />
                            Show the “what would you keep here” calculator (uses
                            the plan you chose for recruits)
                        </label>
                        {canEdit ? (
                            <Button
                                className="justify-self-start"
                                disabled={form.processing}
                            >
                                Save
                            </Button>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Only the owner can change the careers page.
                            </p>
                        )}
                    </fieldset>
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

                <section
                    className="grid gap-2 rounded-lg border p-4"
                    aria-labelledby="words"
                >
                    <h2 id="words" className="font-semibold">
                        In your agents' words
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Only agents can add their own words, and they can take
                        them down any time. Send them this link:{' '}
                        <strong>/recruiting/my-story</strong>
                    </p>
                    {stories.length === 0 ? (
                        <p className="text-sm">None yet.</p>
                    ) : (
                        <ul className="grid gap-2">
                            {stories.map((s) => (
                                <li
                                    key={s.name}
                                    className="rounded border p-3 text-sm"
                                >
                                    “{s.quote}” — <strong>{s.name}</strong>
                                    {s.deals !== null &&
                                        ` · ${s.deals} closing(s) in 12 months`}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
