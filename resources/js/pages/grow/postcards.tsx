import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FeatureOff } from '@/components/feature-off/feature-off';
import { Mail } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Run = {
    ulid: string;
    kind: string;
    size: string;
    headline: string;
    status: string;
    cards: number;
    undeliverable: number;
    credits: number;
    send_date: string | null;
    created: string;
    cancellable: boolean;
};

const select = 'h-12 rounded-md border bg-background px-3';

/**
 * Grow → Postcards (specs/connect/18, C7c): pick who, write the card, see the price and the card, approve. Cards are
 * printed and mailed the next business day and can be cancelled until then.
 */
export default function Postcards({
    ready,
    kinds,
    lists,
    from,
    prices,
    runs,
}: {
    ready: boolean;
    kinds: Record<string, string>;
    lists: { key: string; name: string; count: number }[];
    from: {
        name: string;
        address_line1: string;
        city: string;
        state: string;
        zip: string;
    } | null;
    prices: { '4x6': number; '6x9': number } | null;
    runs: Run[];
}) {
    const open = new URLSearchParams(usePage().url.split('?')[1] ?? '').get(
        'run',
    );
    const draft = runs.find((r) => r.ulid === open && r.status === 'draft');
    const form = useForm({
        kind: 'just_listed',
        size: '4x6',
        smart_list: lists[0]?.key ?? '',
        listing: '',
        headline: '',
        message: '',
        link: '',
        from_name: from?.name ?? '',
        from_line1: from?.address_line1 ?? '',
        from_city: from?.city ?? '',
        from_state: from?.state ?? '',
        from_zip: from?.zip ?? '',
    });
    const field = (k: keyof typeof form.data, label: string, max = 100) => (
        <label className="grid gap-1">
            {label}
            <Input
                maxLength={max}
                value={form.data[k]}
                onChange={(e) => form.setData(k, e.target.value)}
            />
            {form.errors[k] && (
                <span className="text-destructive">{form.errors[k]}</span>
            )}
        </label>
    );

    if (!ready) {
        return (
            <div className="mx-auto grid w-full max-w-6xl gap-5 p-4 md:p-6">
                <Head title="Postcards" />
                <FeatureOff
                    feature="postcards"
                    icon={Mail}
                    title="Postcards"
                    does="Just listed, just sold, open house and neighbourhood postcards, printed and mailed for you."
                    points={[
                        'Sent to a smart list of your contacts',
                        'Printed and mailed for you',
                        'Paid with credits, only when you send',
                    ]}
                />
            </div>
        );
    }

    return (
        <>
            <Head title="Postcards" />
            <div className="grid max-w-3xl gap-6 p-4 text-base">
                <PageHeader
                    title="Postcards"
                    description="Just listed, just sold, open house and neighbourhood postcards to a smart list, printed and mailed for you, paid with credits."
                />

                {draft && (
                    <section className="grid gap-3 rounded-lg border border-primary/50 p-4">
                        <h2 className="font-medium">
                            Check and approve: {draft.headline}
                        </h2>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {(['front', 'back'] as const).map((side) => (
                                <iframe
                                    key={side}
                                    title={`Card ${side}`}
                                    src={`/postcards/${draft.ulid}/preview?side=${side}`}
                                    className="aspect-[3/2] w-full rounded border"
                                    sandbox=""
                                />
                            ))}
                        </div>
                        <p>
                            {draft.cards.toLocaleString()} cards ·{' '}
                            {draft.credits.toLocaleString()} credits. Addresses
                            are checked first; credits for any that can't be
                            mailed come back.
                        </p>
                        <div className="flex gap-2">
                            <Button
                                onClick={() =>
                                    router.post(
                                        `/postcards/${draft.ulid}/approve`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                disabled={draft.cards === 0}
                            >
                                Approve and mail
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => router.get('/postcards')}
                            >
                                Change
                            </Button>
                        </div>
                    </section>
                )}

                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/postcards');
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-3">
                        <label className="grid gap-1">
                            Card
                            <select
                                className={select}
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
                        <label className="grid gap-1">
                            Size
                            <select
                                className={select}
                                value={form.data.size}
                                onChange={(e) =>
                                    form.setData('size', e.target.value)
                                }
                            >
                                <option value="4x6">
                                    4×6 ({prices?.['4x6']} credits a card)
                                </option>
                                <option value="6x9">
                                    6×9 ({prices?.['6x9']} credits a card)
                                </option>
                            </select>
                        </label>
                        <label className="grid gap-1">
                            Who
                            <select
                                className={select}
                                value={form.data.smart_list}
                                onChange={(e) =>
                                    form.setData('smart_list', e.target.value)
                                }
                            >
                                {lists.map((l) => (
                                    <option key={l.key} value={l.key}>
                                        {l.name} ({l.count})
                                    </option>
                                ))}
                            </select>
                            {form.errors.smart_list && (
                                <span className="text-destructive">
                                    {form.errors.smart_list}
                                </span>
                            )}
                        </label>
                    </div>
                    {field('headline', 'Headline (front)', 60)}
                    <label className="grid gap-1">
                        Message (back)
                        <textarea
                            className="min-h-24 rounded-md border bg-background p-3"
                            maxLength={400}
                            value={form.data.message}
                            onChange={(e) =>
                                form.setData('message', e.target.value)
                            }
                        />
                        {form.errors.message && (
                            <span className="text-destructive">
                                {form.errors.message}
                            </span>
                        )}
                    </label>
                    {field(
                        'link',
                        'QR code opens (your listing or funnel page)',
                        400,
                    )}
                    <fieldset className="grid gap-3 sm:grid-cols-2">
                        <legend className="mb-1">Return address</legend>
                        {field('from_name', 'Name', 40)}
                        {field('from_line1', 'Street', 64)}
                        {field('from_city', 'City', 40)}
                        <div className="grid grid-cols-2 gap-3">
                            {field('from_state', 'State', 2)}
                            {field('from_zip', 'ZIP', 10)}
                        </div>
                    </fieldset>
                    <p className="text-sm text-muted-foreground">
                        People tagged “Do not mail” never get cards. Describe
                        the home and the area, not who should live there.
                    </p>
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            See the card and the price
                        </Button>
                    </div>
                </form>

                {runs.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="font-medium">Your mailings</h2>
                        <ul className="grid gap-2">
                            {runs.map((r) => (
                                <li
                                    key={r.ulid}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm"
                                >
                                    <span>
                                        <span className="font-medium">
                                            {r.headline}
                                        </span>{' '}
                                        <Badge variant="outline">
                                            {r.status}
                                        </Badge>
                                        <br />
                                        <span className="text-muted-foreground">
                                            {kinds[r.kind]} · {r.size} ·{' '}
                                            {r.cards} cards
                                            {r.undeliverable > 0
                                                ? ` (${r.undeliverable} couldn't be mailed)`
                                                : ''}{' '}
                                            · {r.credits} credits
                                            {r.send_date
                                                ? ` · prints ${r.send_date}`
                                                : ''}
                                        </span>
                                    </span>
                                    {r.cancellable && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/postcards/${r.ulid}/cancel`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Cancel
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
