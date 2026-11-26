import { Head, Link, useForm } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Props = {
    page: {
        headline: string;
        pitch: string;
        reasons: string[];
        video: string | null;
        extra_states: string[];
    };
    states: Record<string, string>;
    licensed: string[];
    link: string;
    plans: string[];
};

/**
 * CM4b: the owner's join page: the words and video new agents see when they join through an agent's link, and the
 * states they can join from (the brokerage's licensed states, plus states it works in through a managing broker).
 */
export default function JoinPage({
    page,
    states,
    licensed,
    link,
    plans,
}: Props) {
    const form = useForm({
        headline: page.headline,
        pitch: page.pitch,
        reasons: page.reasons.join('\n'),
        video: page.video ?? '',
        extra_states: page.extra_states,
    });
    const toggle = (code: string) =>
        form.setData(
            'extra_states',
            form.data.extra_states.includes(code)
                ? form.data.extra_states.filter((c) => c !== code)
                : [...form.data.extra_states, code],
        );

    return (
        <>
            <Head title="Join page" />
            <div className="flex max-w-4xl flex-col gap-5 p-4 md:p-6">
                <PageHeader
                    title="Join page"
                    description="What new agents see when they join through an agent's link: your opportunity, a video and the states they can join from."
                    actions={
                        <Button variant="outline" asChild>
                            <a
                                href={link}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ExternalLink aria-hidden="true" /> See the page
                            </a>
                        </Button>
                    }
                />
                <p className="rounded-xl border bg-card p-4 text-[15px]">
                    {plans.length > 1
                        ? `New agents pick how they are paid: ${plans.join(' or ')}.`
                        : plans.length === 1
                          ? `New agents join on ${plans[0]}.`
                          : 'New agents join on your default commission plan.'}{' '}
                    <Link
                        href="/compensation/plans"
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Choose the plans they can pick
                    </Link>
                </p>
                <form
                    className="grid gap-5 rounded-xl border bg-card p-5 text-[15px]"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/recruiting/join-page', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="grid gap-1 font-medium">
                        Headline
                        <Input
                            value={form.data.headline}
                            maxLength={90}
                            onChange={(e) =>
                                form.setData('headline', e.target.value)
                            }
                        />
                        {form.errors.headline && (
                            <span
                                role="alert"
                                className="text-sm font-normal text-destructive"
                            >
                                {form.errors.headline}
                            </span>
                        )}
                    </label>
                    <label className="grid gap-1 font-medium">
                        Your opportunity, in a few sentences
                        <Textarea
                            rows={4}
                            maxLength={600}
                            value={form.data.pitch}
                            onChange={(e) =>
                                form.setData('pitch', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1 font-medium">
                        Reasons to join (one per line, up to 6)
                        <Textarea
                            rows={5}
                            value={form.data.reasons}
                            onChange={(e) =>
                                form.setData('reasons', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1 font-medium">
                        Your video (YouTube, Vimeo, or an https link to an MP4)
                        <Input
                            type="url"
                            placeholder="https://www.youtube.com/watch?v=…"
                            value={form.data.video}
                            onChange={(e) =>
                                form.setData('video', e.target.value)
                            }
                        />
                        {form.errors.video && (
                            <span
                                role="alert"
                                className="text-sm font-normal text-destructive"
                            >
                                {form.errors.video}
                            </span>
                        )}
                    </label>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 font-medium">
                            States agents can join from
                        </legend>
                        <p className="text-sm text-muted-foreground">
                            {licensed.length > 0
                                ? `Your licensed states are always included (${licensed.join(', ')}). Tick any other state where you work through a managing broker.`
                                : 'Tick the states where agents can join. Add your brokerage licences under Settings › Licences and those states are included automatically.'}
                        </p>
                        <div className="grid max-h-72 gap-1 overflow-y-auto rounded-lg border p-3 sm:grid-cols-3">
                            {Object.entries(states).map(([code, name]) => (
                                <label
                                    key={code}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={
                                            licensed.includes(code) ||
                                            form.data.extra_states.includes(
                                                code,
                                            )
                                        }
                                        disabled={licensed.includes(code)}
                                        onChange={() => toggle(code)}
                                    />
                                    {name}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Save join page
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

JoinPage.layout = {
    breadcrumbs: [{ title: 'Join page', href: '/recruiting/join-page' }],
};
