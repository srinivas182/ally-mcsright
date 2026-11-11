import { router, useForm } from '@inertiajs/react';
import {
    CalendarIcon,
    MailIcon,
    MessageSquareIcon,
    PhoneIcon,
    PinIcon,
    StickyNoteIcon,
    ZapIcon,
} from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

export type TimelineItem = {
    ulid: string;
    type: 'note' | 'call' | 'meeting' | 'email' | 'text' | 'system';
    direction: 'in' | 'out' | null;
    outcome: string | null;
    duration_minutes: number | null;
    subject: string | null;
    body: string | null;
    pinned: boolean;
    by: string | null;
    occurred_at: string;
};

const icons = {
    note: StickyNoteIcon,
    call: PhoneIcon,
    meeting: CalendarIcon,
    email: MailIcon,
    text: MessageSquareIcon,
    system: ZapIcon,
};
const outcomes: Record<string, string> = {
    connected: 'Connected',
    left_voicemail: 'Left voicemail',
    no_answer: 'No answer',
    wrong_number: 'Wrong number',
};

/** Log a note, call, meeting, email or text in a couple of taps. */
function LogBar({ contactUlid }: { contactUlid: string }) {
    const form = useForm({ type: 'note', body: '', outcome: '' });
    const submit = (outcome?: string) =>
        form.transform((d) => ({
            ...d,
            outcome: outcome ?? (d.outcome || null),
        }));

    return (
        <div className="grid gap-2 rounded-lg border p-3">
            <Tabs
                value={form.data.type}
                onValueChange={(type) => form.setData('type', type)}
            >
                <TabsList>
                    <TabsTrigger value="note">Note</TabsTrigger>
                    <TabsTrigger value="call">Call</TabsTrigger>
                    <TabsTrigger value="meeting">Meeting</TabsTrigger>
                    <TabsTrigger value="email">Email</TabsTrigger>
                    <TabsTrigger value="text">Text</TabsTrigger>
                </TabsList>
            </Tabs>
            <Textarea
                aria-label="What happened?"
                placeholder={
                    form.data.type === 'call'
                        ? 'Notes from the call (optional)'
                        : 'What happened?'
                }
                value={form.data.body}
                onChange={(e) => form.setData('body', e.target.value)}
            />
            {(form.errors.body || form.errors.outcome) && (
                <p className="text-sm text-destructive">
                    {form.errors.body ?? form.errors.outcome}
                </p>
            )}
            <div className="flex flex-wrap justify-end gap-2">
                {form.data.type === 'call' ? (
                    Object.entries(outcomes).map(([key, label]) => (
                        <Button
                            key={key}
                            size="sm"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => {
                                submit(key);
                                form.post(
                                    `/contacts/${contactUlid}/activities`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => form.reset('body'),
                                    },
                                );
                            }}
                        >
                            {label}
                        </Button>
                    ))
                ) : (
                    <Button
                        size="sm"
                        disabled={
                            form.processing || form.data.body.trim() === ''
                        }
                        onClick={() => {
                            submit();
                            form.post(`/contacts/${contactUlid}/activities`, {
                                preserveScroll: true,
                                onSuccess: () => form.reset('body'),
                            });
                        }}
                    >
                        Save
                    </Button>
                )}
            </div>
        </div>
    );
}

export function Timeline({
    contactUlid,
    items,
}: {
    contactUlid: string;
    items: TimelineItem[];
}) {
    return (
        <section aria-label="Timeline" className="grid gap-4">
            <LogBar contactUlid={contactUlid} />
            <ol className="grid gap-3">
                {items.map((item) => {
                    const Icon = icons[item.type];
                    return (
                        <li
                            key={item.ulid}
                            className={cn(
                                'flex gap-3 rounded-lg p-2',
                                item.pinned &&
                                    'bg-amber-50 dark:bg-amber-950/40',
                            )}
                        >
                            <Icon
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            />
                            <div className="grid min-w-0 flex-1 gap-0.5 text-sm">
                                <p
                                    className={cn(
                                        item.type === 'system' &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {item.type === 'call' && item.outcome && (
                                        <span className="font-medium">
                                            Call · {outcomes[item.outcome]}{' '}
                                        </span>
                                    )}
                                    {item.type === 'meeting' && (
                                        <span className="font-medium">
                                            Meeting{' '}
                                        </span>
                                    )}
                                    {(item.type === 'email' ||
                                        item.type === 'text') && (
                                        <span className="font-medium capitalize">
                                            {item.type}{' '}
                                            {item.direction === 'in'
                                                ? 'received'
                                                : 'sent'}{' '}
                                        </span>
                                    )}
                                    <span className="whitespace-pre-wrap">
                                        {item.body}
                                    </span>
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {new Date(item.occurred_at).toLocaleString(
                                        undefined,
                                        {
                                            dateStyle: 'medium',
                                            timeStyle: 'short',
                                        },
                                    )}
                                    {item.by ? ` · ${item.by}` : ''}
                                </p>
                            </div>
                            {item.type !== 'system' && (
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label={
                                        item.pinned ? 'Unpin' : 'Pin to top'
                                    }
                                    onClick={() =>
                                        router.post(
                                            `/activities/${item.ulid}/pin`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <PinIcon
                                        className={cn(
                                            'size-4',
                                            item.pinned && 'fill-current',
                                        )}
                                    />
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}
