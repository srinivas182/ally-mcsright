import { Head, Link } from '@inertiajs/react';
import {
    MailIcon,
    MessageSquareIcon,
    PhoneIcon,
    SparklesIcon,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

type Pick = {
    id: number;
    ulid: string;
    name: string;
    points: number;
    reason: string;
    more: string[];
    phone: string | null;
    email: string | null;
    can_call: boolean;
    can_text: boolean;
    has_brief: boolean;
};

/** Who to call today and why. */
export default function Today({ picks }: { picks: Pick[] }) {
    return (
        <>
            <Head title="Today's 10" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Today's 10"
                    description="The people most worth your time today, and why. Talk to someone and they come off the list."
                    actions={
                        picks.length > 0 && (
                            <Button asChild>
                                <Link href="/contacts/work?list=today">
                                    Start calling
                                </Link>
                            </Button>
                        )
                    }
                />
                {picks.length === 0 ? (
                    <EmptyState
                        title="All done for today"
                        description="Nothing urgent. A good moment to work a keep-in-touch list."
                    />
                ) : (
                    <ol className="grid gap-3">
                        {picks.map((p, i) => (
                            <li
                                key={p.ulid}
                                className="grid gap-2 rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <Link
                                        href={`/contacts/${p.ulid}`}
                                        className="text-lg font-semibold hover:underline"
                                    >
                                        {i + 1}. {p.name}
                                    </Link>
                                    {p.has_brief && (
                                        <Badge
                                            variant="secondary"
                                            className="gap-1"
                                        >
                                            <SparklesIcon
                                                aria-hidden="true"
                                                className="size-3"
                                            />{' '}
                                            Brief ready
                                        </Badge>
                                    )}
                                </div>
                                <p className="text-sm">{p.reason}</p>
                                {p.more.length > 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        Also: {p.more.join(' · ')}
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    {p.phone && p.can_call && (
                                        <Button size="sm" asChild>
                                            <a href={`tel:${p.phone}`}>
                                                <PhoneIcon aria-hidden="true" />{' '}
                                                Call
                                            </a>
                                        </Button>
                                    )}
                                    {p.phone && p.can_text && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <a href={`sms:${p.phone}`}>
                                                <MessageSquareIcon aria-hidden="true" />{' '}
                                                Text
                                            </a>
                                        </Button>
                                    )}
                                    {p.email && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <a href={`mailto:${p.email}`}>
                                                <MailIcon aria-hidden="true" />{' '}
                                                Email
                                            </a>
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </>
    );
}

Today.layout = { breadcrumbs: [{ title: "Today's 10", href: '/today' }] };
