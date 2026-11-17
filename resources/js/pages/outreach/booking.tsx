import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    link: {
        slug: string;
        title: string;
        intro: string;
        kind: string;
        active: boolean;
        bookings: number;
        url: string | null;
        hours_on: boolean;
    };
    kinds: Record<string, string>;
    website: boolean;
};

/** MK1: the agent's own booking link on the brokerage website. */
export default function Booking({ link, kinds, website }: Props) {
    const form = useForm({
        slug: link.slug,
        title: link.title,
        intro: link.intro,
        kind: link.kind,
        active: link.active,
    });
    const [copied, setCopied] = useState(false);

    return (
        <>
            <Head title="My booking link" />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="My booking link"
                    description="Put it in your email signature, texts and social profiles. People pick a free time, and it lands on your task list and calendar."
                />
                {!link.hours_on && (
                    <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                        First set the hours you take bookings in{' '}
                        <Link
                            className="underline"
                            href="/settings/availability"
                        >
                            Booking hours
                        </Link>
                        , so people only see times you are free.
                    </p>
                )}
                {link.url && (
                    <section className="flex flex-wrap items-center gap-2 rounded-lg border p-4">
                        <code className="rounded bg-muted px-2 py-1 text-base break-all">
                            {link.url}
                        </code>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                                void navigator.clipboard
                                    ?.writeText(link.url ?? '')
                                    .then(() => setCopied(true));
                            }}
                        >
                            {copied ? 'Copied' : 'Copy link'}
                        </Button>
                        <span className="text-muted-foreground">
                            {link.bookings} booked so far
                        </span>
                    </section>
                )}
                <form
                    className="grid gap-4 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/outreach/booking', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="grid gap-1">
                        Your link ends with
                        <Input
                            value={form.data.slug}
                            onChange={(e) =>
                                form.setData('slug', e.target.value)
                            }
                        />
                        <span className="text-sm text-muted-foreground">
                            yourwebsite.com/meet/{form.data.slug || 'your-name'}
                        </span>
                        {form.errors.slug && (
                            <span className="text-destructive">
                                {form.errors.slug}
                            </span>
                        )}
                    </label>
                    <label className="grid gap-1">
                        Heading
                        <Input
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        A few words for the page (optional)
                        <textarea
                            className="min-h-20 rounded-md border bg-background p-2"
                            value={form.data.intro}
                            onChange={(e) =>
                                form.setData('intro', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        What people book
                        <select
                            className="rounded-md border bg-background p-2"
                            value={form.data.kind}
                            onChange={(e) =>
                                form.setData('kind', e.target.value)
                            }
                        >
                            {Object.entries(kinds).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.active}
                            onChange={(e) =>
                                form.setData('active', e.target.checked)
                            }
                        />
                        Turn my booking link on
                    </label>
                    {form.errors.active && (
                        <p className="text-destructive">{form.errors.active}</p>
                    )}
                    {!website && (
                        <p className="text-sm text-muted-foreground">
                            It goes live when your brokerage website is live.
                        </p>
                    )}
                    <div>
                        <Button disabled={form.processing}>Save</Button>
                    </div>
                </form>
            </div>
        </>
    );
}
