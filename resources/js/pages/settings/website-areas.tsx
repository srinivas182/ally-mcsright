import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

type Area = {
    kind: 'city' | 'zip' | 'area';
    slug: string;
    name: string;
    count: number;
    intro: string | null;
    hidden: boolean;
};

const KIND: Record<Area['kind'], string> = {
    city: 'City',
    area: 'Neighborhood',
    zip: 'ZIP code',
};

/**
 * IDX9: Settings › Website › Area pages. Every city, neighborhood and ZIP code with enough homes for sale gets a page
 * automatically. Add a few words of your own to any of them, or switch one off.
 */
export default function WebsiteAreas({
    areas,
    sample,
    max,
}: {
    areas: Area[];
    sample: boolean;
    max: number;
}) {
    const [find, setFind] = useState('');
    const shown = useMemo(
        () =>
            areas.filter((a) =>
                a.name.toLowerCase().includes(find.trim().toLowerCase()),
            ),
        [areas, find],
    );

    return (
        <>
            <Head title="Area pages" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Area pages"
                    description="Your website makes a page for every city, neighborhood and ZIP code with homes for sale: live prices, the newest homes and common questions. Add a few words of your own to the areas you know best; pages with local knowledge do better on Google."
                />
                {sample && (
                    <p className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-base text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                        Your website shows sample listings for now, so these
                        pages are kept out of Google until your first MLS board
                        is live.
                    </p>
                )}
                {areas.length === 0 ? (
                    <p className="rounded-xl border bg-card p-6 text-center text-base text-muted-foreground">
                        Area pages appear here as soon as your website has homes
                        for sale.
                    </p>
                ) : (
                    <>
                        <label className="grid max-w-sm gap-1 text-sm">
                            Find an area
                            <Input
                                value={find}
                                onChange={(e) => setFind(e.target.value)}
                                placeholder="City, neighborhood or ZIP"
                            />
                        </label>
                        <ul className="grid gap-3">
                            {shown.map((a) => (
                                <AreaRow key={a.slug} area={a} max={max} />
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </>
    );
}

function AreaRow({ area: a, max }: { area: Area; max: number }) {
    const [editing, setEditing] = useState(false);
    const [intro, setIntro] = useState(a.intro ?? '');
    const save = (hidden: boolean, words: string | null) =>
        router.put(
            `/settings/website/areas/${a.slug}`,
            { intro: words, hidden },
            { preserveScroll: true, onSuccess: () => setEditing(false) },
        );

    return (
        <li className="grid gap-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="font-semibold">
                        {a.hidden ? (
                            a.name
                        ) : (
                            <a
                                href={`/market/${a.slug}`}
                                target="_blank"
                                rel="noreferrer"
                                className="underline"
                            >
                                {a.name}
                            </a>
                        )}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {KIND[a.kind]} · {a.count.toLocaleString('en-US')} homes
                        for sale
                        {a.intro ? ' · has your words' : ''}
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    {a.hidden && (
                        <Badge variant="secondary">Switched off</Badge>
                    )}
                    <label className="flex items-center gap-2 text-sm">
                        Show page
                        <Switch
                            checked={!a.hidden}
                            onCheckedChange={(on) => save(!on, a.intro)}
                        />
                    </label>
                    {!editing && (
                        <Button
                            variant="outline"
                            onClick={() => setEditing(true)}
                        >
                            {a.intro ? 'Edit your words' : 'Add your words'}
                        </Button>
                    )}
                </div>
            </div>
            {editing && (
                <div className="grid gap-2">
                    <Textarea
                        rows={5}
                        maxLength={max}
                        value={intro}
                        onChange={(e) => setIntro(e.target.value)}
                        placeholder="What it is like to live here: streets people love, schools, parks, what homes sell for and why. Keep it about the place, not the people (Fair Housing)."
                    />
                    <div className="flex gap-3">
                        <Button onClick={() => save(a.hidden, intro)}>
                            Save
                        </Button>
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setIntro(a.intro ?? '');
                                setEditing(false);
                            }}
                        >
                            Cancel
                        </Button>
                    </div>
                </div>
            )}
        </li>
    );
}
