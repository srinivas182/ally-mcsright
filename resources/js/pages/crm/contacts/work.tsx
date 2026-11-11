import { Head, Link, router } from '@inertiajs/react';
import { MailIcon, MessageSquareIcon, PhoneIcon } from 'lucide-react';

import { AiBrief, type Brief } from '@/components/crm/ai-brief';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { Progress } from '@/components/ui/progress';

type Props = {
    list: { key: string; name: string };
    position: { done: number; total: number };
    contact: {
        id: number;
        ulid: string;
        name: string;
        phone: string | null;
        email: string | null;
        lifecycle: string;
        last_activity_at: string | null;
        can_call: boolean;
        can_text: boolean;
    } | null;
    brief: Brief;
};

const outcomes = [
    ['connected', 'Connected'],
    ['left_voicemail', 'Left voicemail'],
    ['no_answer', 'No answer'],
] as const;

/** Work my list: one contact at a time — call or text, tap the outcome, next. */
export default function WorkList({ list, position, contact, brief }: Props) {
    const next = (after: number) =>
        router.get(
            '/contacts/work',
            { list: list.key || undefined, after },
            { preserveScroll: false },
        );
    const log = (outcome: string) => {
        if (!contact) return;
        router.post(
            `/contacts/${contact.ulid}/activities`,
            { type: 'call', outcome },
            { onSuccess: () => next(contact.id) },
        );
    };

    return (
        <>
            <Head title={`Working: ${list.name}`} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title={list.name}
                    description={`${Math.min(position.done + (contact ? 1 : 0), position.total)} of ${position.total}`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link
                                href={`/contacts${list.key ? `?list=${list.key}` : ''}`}
                            >
                                Stop
                            </Link>
                        </Button>
                    }
                />
                <Progress
                    value={
                        position.total
                            ? (position.done / position.total) * 100
                            : 100
                    }
                    aria-label="Progress through the list"
                />

                {!contact ? (
                    <EmptyState
                        title="List done!"
                        description="Nice work. Every contact on this list has been worked."
                    />
                ) : (
                    <>
                        <div className="grid gap-1">
                            <Link
                                href={`/contacts/${contact.ulid}`}
                                className="text-xl font-semibold hover:underline"
                            >
                                {contact.name}
                            </Link>
                            <p className="text-sm text-muted-foreground">
                                Last contact:{' '}
                                {contact.last_activity_at
                                    ? new Date(
                                          contact.last_activity_at,
                                      ).toLocaleDateString()
                                    : 'never'}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {contact.phone && contact.can_call && (
                                <Button size="lg" asChild>
                                    <a href={`tel:${contact.phone}`}>
                                        <PhoneIcon aria-hidden="true" /> Call
                                    </a>
                                </Button>
                            )}
                            {contact.phone && contact.can_text && (
                                <Button size="lg" variant="outline" asChild>
                                    <a href={`sms:${contact.phone}`}>
                                        <MessageSquareIcon aria-hidden="true" />{' '}
                                        Text
                                    </a>
                                </Button>
                            )}
                            {contact.email && (
                                <Button size="lg" variant="outline" asChild>
                                    <a href={`mailto:${contact.email}`}>
                                        <MailIcon aria-hidden="true" /> Email
                                    </a>
                                </Button>
                            )}
                            {contact.phone && !contact.can_call && (
                                <p className="self-center text-sm text-destructive">
                                    Do Not Call
                                </p>
                            )}
                        </div>
                        <AiBrief
                            contactUlid={contact.ulid}
                            brief={brief}
                            phone={contact.phone}
                            email={contact.email}
                            compact
                        />
                        <div className="grid gap-2">
                            <p className="text-sm font-medium">
                                How did it go?
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {outcomes.map(([key, label]) => (
                                    <Button
                                        key={key}
                                        variant="outline"
                                        onClick={() => log(key)}
                                    >
                                        {label}
                                    </Button>
                                ))}
                                <Button
                                    variant="ghost"
                                    onClick={() => next(contact.id)}
                                >
                                    Skip
                                </Button>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

WorkList.layout = {
    breadcrumbs: [
        { title: 'Contacts', href: '/contacts' },
        { title: 'Work my list', href: '#' },
    ],
};
