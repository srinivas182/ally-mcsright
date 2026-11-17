import { Head, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    requests: {
        id: number;
        contact: string;
        occasion: string;
        message: string;
        status: string;
        created_at: string;
    }[];
    occasions: Record<string, string>;
    max: number;
    suggest: {
        ulid: string;
        name: string;
        occasion: string;
        has_address: boolean;
    }[];
    contact: { ulid: string; name: string } | null;
};

/** MK2: handwritten cards, written with a real pen by a handwriting partner and mailed. */
export default function Cards({
    requests,
    occasions,
    max,
    suggest,
    contact,
}: Props) {
    const form = useForm({
        contact: contact?.ulid ?? '',
        name: contact?.name ?? '',
        occasion: 'thank_you',
        message: '',
    });

    return (
        <>
            <Head title="Handwritten cards" />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Handwritten cards"
                    description="Write a short note; it is written by hand with a real pen and mailed to them. Nothing beats it for birthdays, home anniversaries and thank-yous."
                />
                {suggest.length > 0 && (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <h2 className="font-semibold">
                            Good moments this week
                        </h2>
                        <ul className="flex flex-wrap gap-2">
                            {suggest.map((s) => (
                                <li key={s.ulid + s.occasion}>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={!s.has_address}
                                        title={
                                            s.has_address
                                                ? undefined
                                                : 'Add their mailing address first'
                                        }
                                        onClick={() =>
                                            form.setData({
                                                ...form.data,
                                                contact: s.ulid,
                                                name: s.name,
                                                occasion: s.occasion,
                                            })
                                        }
                                    >
                                        {s.name} · {occasions[s.occasion]}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {form.data.contact !== '' ? (
                    <form
                        className="grid gap-3 rounded-lg border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/outreach/cards', {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <h2 className="font-semibold">
                            A card for {form.data.name}
                        </h2>
                        <label className="grid gap-1">
                            Occasion
                            <select
                                className="rounded-md border bg-background p-2"
                                value={form.data.occasion}
                                onChange={(e) =>
                                    form.setData('occasion', e.target.value)
                                }
                            >
                                {Object.entries(occasions).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1">
                            Your note ({form.data.message.length}/{max})
                            <textarea
                                className="min-h-28 rounded-md border bg-background p-2 text-base"
                                maxLength={max}
                                value={form.data.message}
                                onChange={(e) =>
                                    form.setData('message', e.target.value)
                                }
                            />
                        </label>
                        {(form.errors.message || form.errors.contact) && (
                            <p className="text-destructive">
                                {form.errors.message ?? form.errors.contact}
                            </p>
                        )}
                        <div>
                            <Button disabled={form.processing}>
                                Send the card
                            </Button>
                        </div>
                    </form>
                ) : (
                    <p className="text-muted-foreground">
                        Pick someone above, or use &ldquo;Send a handwritten
                        card&rdquo; from a contact.
                    </p>
                )}
                <section className="grid gap-2">
                    <h2 className="font-semibold">Your cards</h2>
                    {requests.length === 0 && (
                        <p className="text-muted-foreground">None yet.</p>
                    )}
                    {requests.map((r) => (
                        <div
                            key={r.id}
                            className="flex flex-wrap items-center gap-2 rounded-lg border p-3"
                        >
                            <span className="font-medium">{r.contact}</span>
                            <span className="text-muted-foreground">
                                {r.occasion}
                            </span>
                            <Badge
                                variant={
                                    r.status === 'sent' ? 'default' : 'outline'
                                }
                                className="ml-auto"
                            >
                                {r.status === 'sent'
                                    ? 'Mailed'
                                    : 'Being written'}
                            </Badge>
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}
