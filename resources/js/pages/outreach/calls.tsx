import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Person = {
    ulid: string;
    name: string;
    phone: string;
    city: string | null;
    state: string | null;
    last_activity_at: string | null;
    local_time: string;
    notes: string[];
};
type Props = {
    lists: { key: string; name: string; count: number }[];
    list: string | null;
    queue: {
        ready: Person[];
        skipped: {
            do_not_call: number;
            no_phone: number;
            called_today: number;
            quiet_hours: number;
        };
        total: number;
    } | null;
    outcomes: Record<string, string>;
};

/** MK1: the call queue: one person at a time, tap to call from your own phone, one tap to log how it went. */
export default function Calls({ lists, list, queue, outcomes }: Props) {
    const [index, setIndex] = useState(0);
    const form = useForm({ outcome: '', note: '' });
    const person = queue?.ready[index] ?? null;
    const skipped = queue
        ? Object.entries(queue.skipped).filter(([, n]) => n > 0)
        : [];
    const skipLabels: Record<string, string> = {
        do_not_call: 'marked Do Not Call',
        no_phone: 'no phone number',
        called_today: 'already called today',
        quiet_hours: 'before 8am or after 9pm where they live',
    };

    const log = (outcome: string) => {
        if (!person) return;
        form.transform((d) => ({ ...d, outcome }));
        form.post(`/outreach/calls/${person.ulid}`, {
            preserveScroll: true,
            preserveState: true,
            only: [],
            onSuccess: () => {
                form.reset();
                setIndex((i) => i + 1);
            },
        });
    };

    return (
        <>
            <Head title="Call queue" />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Call queue"
                    description="Pick a list and work through it one person at a time. You call from your own phone; we skip anyone marked Do Not Call and anyone it is too early or too late to call."
                />
                <label className="grid gap-1">
                    Who to call
                    <select
                        className="rounded-md border bg-background p-2 text-base"
                        value={list ?? ''}
                        onChange={(e) => {
                            setIndex(0);
                            router.get(
                                '/outreach/calls',
                                e.target.value ? { list: e.target.value } : {},
                            );
                        }}
                    >
                        <option value="">Choose a list…</option>
                        {lists.map((l) => (
                            <option key={l.key} value={l.key}>
                                {l.name} ({l.count})
                            </option>
                        ))}
                    </select>
                </label>
                {queue && (
                    <p className="text-muted-foreground">
                        {queue.ready.length} to call now
                        {skipped.length > 0 &&
                            ` · skipped: ${skipped.map(([k, n]) => `${n} ${skipLabels[k]}`).join(', ')}`}
                    </p>
                )}
                {queue && person && (
                    <section className="grid gap-3 rounded-lg border p-5">
                        <p className="text-sm text-muted-foreground">
                            {index + 1} of {queue.ready.length}
                        </p>
                        <h2 className="text-2xl font-semibold">
                            {person.name}
                        </h2>
                        <p className="text-muted-foreground">
                            {[person.city, person.state]
                                .filter(Boolean)
                                .join(', ')}
                            {' · '}
                            {person.local_time} where they are
                            {person.last_activity_at &&
                                ` · last in touch ${person.last_activity_at}`}
                        </p>
                        {person.notes.length > 0 && (
                            <ul className="grid gap-1 rounded-md bg-muted/50 p-3 text-sm">
                                {person.notes.map((n, i) => (
                                    <li key={i}>{n}</li>
                                ))}
                            </ul>
                        )}
                        <Button asChild size="lg" className="w-fit text-lg">
                            <a href={`tel:${person.phone}`}>
                                Call {person.phone}
                            </a>
                        </Button>
                        <label className="grid gap-1">
                            Note (optional)
                            <textarea
                                className="min-h-16 rounded-md border bg-background p-2"
                                value={form.data.note}
                                onChange={(e) =>
                                    form.setData('note', e.target.value)
                                }
                            />
                        </label>
                        <div className="flex flex-wrap gap-2">
                            {Object.entries(outcomes).map(([k, label]) => (
                                <Button
                                    key={k}
                                    variant={
                                        k === 'do_not_call'
                                            ? 'destructive'
                                            : k === 'connected'
                                              ? 'default'
                                              : 'outline'
                                    }
                                    disabled={form.processing}
                                    onClick={() => log(k)}
                                >
                                    {label}
                                </Button>
                            ))}
                            <Button
                                variant="ghost"
                                onClick={() => setIndex((i) => i + 1)}
                            >
                                Skip
                            </Button>
                        </div>
                    </section>
                )}
                {queue && !person && (
                    <p className="rounded-lg border p-5 text-lg">
                        {queue.ready.length === 0
                            ? 'Nobody to call on this list right now.'
                            : 'All done. Nice work.'}
                    </p>
                )}
                <p className="text-sm text-muted-foreground">
                    Calls are placed by you, one at a time, never by an
                    autodialer. Only call people who asked to hear from you or
                    who know you. If someone asks not to be called, choose
                    &ldquo;Asked not to be called&rdquo; and nobody at the
                    brokerage will be offered their number again.
                </p>
            </div>
        </>
    );
}
