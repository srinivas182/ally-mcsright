import { Head, Link, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Allowances = {
    posts: number;
    reels: number;
    tours: number;
    enhance: number;
    voice: number;
    features: string[];
};

type Pkg = {
    key: string;
    name: string;
    price_cents: number | null;
    allowances: Allowances;
    cost_cents: number;
    margin_pct: number | null;
};

type Track = {
    ulid: string;
    title: string;
    artist: string;
    mood: string;
    licence: string;
    licence_url: string | null;
    seconds: number;
    active: boolean;
};

/** The licensed music library for Reels: only tracks RightAlly holds a licence for. */
function MusicSection({
    music,
    moods,
}: {
    music: Track[];
    moods: Record<string, string>;
}) {
    const form = useForm<{
        file: File | null;
        title: string;
        artist: string;
        mood: string;
        licence: string;
        licence_url: string;
    }>({
        file: null,
        title: '',
        artist: '',
        mood: 'luxury',
        licence: '',
        licence_url: '',
    });

    return (
        <section className="grid gap-3">
            <h2 className="font-medium">Reels music library</h2>
            <p className="text-sm text-muted-foreground">
                Only add tracks RightAlly holds a licence for that covers
                customers&apos; social videos (for example a royalty-free
                library subscription). Agents choose a mood; Studio picks from
                these.
            </p>
            <ul className="grid gap-1 text-sm">
                {music.map((t) => (
                    <li key={t.ulid} className="flex items-center gap-2">
                        <Badge variant={t.active ? 'default' : 'outline'}>
                            {moods[t.mood] ?? t.mood}
                        </Badge>
                        <span>
                            {t.title} · {t.artist} ({t.seconds}s) — {t.licence}
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/hq/studio/music/${t.ulid}`,
                                    { active: !t.active },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t.active ? 'Turn off' : 'Turn on'}
                        </Button>
                    </li>
                ))}
            </ul>
            <form
                className="grid gap-2 rounded-lg border p-3 sm:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/hq/studio/music', {
                        forceFormData: true,
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <label className="grid gap-1 text-sm">
                    Track (MP3, M4A or WAV)
                    <Input
                        type="file"
                        accept=".mp3,.m4a,.aac,.wav"
                        onChange={(e) =>
                            form.setData('file', e.target.files?.[0] ?? null)
                        }
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Title
                    <Input
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Artist
                    <Input
                        value={form.data.artist}
                        onChange={(e) => form.setData('artist', e.target.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Mood
                    <select
                        className="h-10 rounded-md border bg-background px-3"
                        value={form.data.mood}
                        onChange={(e) => form.setData('mood', e.target.value)}
                    >
                        {Object.entries(moods).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    Licence RightAlly holds
                    <Input
                        value={form.data.licence}
                        onChange={(e) =>
                            form.setData('licence', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Licence link (optional)
                    <Input
                        type="url"
                        value={form.data.licence_url}
                        onChange={(e) =>
                            form.setData('licence_url', e.target.value)
                        }
                    />
                </label>
                {Object.values(form.errors).map((m) => (
                    <p key={m} className="text-sm text-destructive">
                        {m}
                    </p>
                ))}
                <div>
                    <Button type="submit" disabled={form.processing}>
                        Add track
                    </Button>
                </div>
            </form>
        </section>
    );
}

const money = (cents: number | null) =>
    cents === null ? 'not priced' : `$${(cents / 100).toFixed(2)}`;

function PackageCard({
    pkg,
    features,
}: {
    pkg: Pkg;
    features: Record<string, string>;
}) {
    const form = useForm({ ...pkg.allowances });
    const fields = [
        ['posts', 'Designed posts'],
        ['reels', 'Reels and Shorts'],
        ['tours', 'YouTube tours'],
        ['enhance', 'Photo enhancements'],
        ['voice', 'Voice narrations'],
    ] as const;

    return (
        <form
            className="grid gap-3 rounded-lg border p-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/hq/studio/packages/${pkg.key}`, {
                    preserveScroll: true,
                });
            }}
        >
            <h2 className="font-medium">
                {pkg.name}{' '}
                <Badge
                    variant={
                        pkg.margin_pct !== null && pkg.margin_pct < 40
                            ? 'destructive'
                            : 'outline'
                    }
                >
                    {money(pkg.price_cents)} a month · full use costs{' '}
                    {money(pkg.cost_cents)}
                    {pkg.margin_pct !== null && ` · ${pkg.margin_pct}% margin`}
                </Badge>
            </h2>
            <div className="grid gap-3 sm:grid-cols-5">
                {fields.map(([k, label]) => (
                    <label key={k} className="grid gap-1 text-sm">
                        {label}
                        <Input
                            type="number"
                            min={0}
                            value={form.data[k]}
                            onChange={(e) =>
                                form.setData(k, Number(e.target.value))
                            }
                        />
                    </label>
                ))}
            </div>
            <fieldset className="flex flex-wrap gap-4 text-sm">
                {Object.entries(features).map(([k, l]) => (
                    <label key={k} className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-5"
                            checked={form.data.features.includes(k)}
                            onChange={(e) =>
                                form.setData(
                                    'features',
                                    e.target.checked
                                        ? [...form.data.features, k]
                                        : form.data.features.filter(
                                              (f) => f !== k,
                                          ),
                                )
                            }
                        />
                        {l}
                    </label>
                ))}
            </fieldset>
            <div>
                <Button type="submit" disabled={form.processing}>
                    Save allowances
                </Button>
            </div>
        </form>
    );
}

/**
 * HQ → Ally Studio (specs/grow/13): allowances and features per package, with price, the cost of a full month's use
 * and the margin; and each extra item's credits, price, cost and margin.
 */
export default function HqStudio({
    packages,
    items,
    services,
    features,
    music,
    moods,
}: {
    packages: Pkg[];
    items: {
        item: string;
        label: string;
        made_with: string;
        credits: number;
        price_cents: number;
        cost_cents: number;
        margin_pct: number | null;
    }[];
    services: { key: string; does: string; on: boolean }[];
    features: Record<string, string>;
    music: Track[];
    moods: Record<string, string>;
}) {
    return (
        <>
            <Head title="Ally Studio" />
            <div className="grid max-w-5xl gap-6 p-4 text-base">
                <PageHeader
                    title="Ally Studio"
                    description="What each Studio package includes, what it costs RightAlly and the margin."
                />
                <p className="text-sm text-muted-foreground">
                    Set package prices under{' '}
                    <Link href="/hq/connect" className="underline">
                        Connect setup
                    </Link>
                    , credits for extra items on the rate card and costs in the
                    cost list, both under{' '}
                    <Link href="/hq/credits" className="underline">
                        RightAlly credits
                    </Link>
                    .
                </p>
                <section className="grid gap-2">
                    <h2 className="font-medium">Services behind Studio</h2>
                    <p className="text-sm text-muted-foreground">
                        All on RightAlly&apos;s own accounts: agents and
                        brokerages just buy a package. Switch each on with its
                        key under Connect setup.
                    </p>
                    <ul className="grid gap-1 text-sm">
                        {services.map((s) => (
                            <li key={s.key} className="flex items-center gap-2">
                                <Badge variant={s.on ? 'default' : 'outline'}>
                                    {s.on ? 'on' : 'off'}
                                </Badge>
                                {s.does}
                            </li>
                        ))}
                    </ul>
                </section>
                {packages.map((p) => (
                    <PackageCard key={p.key} pkg={p} features={features} />
                ))}
                <MusicSection music={music} moods={moods} />
                <section className="grid gap-2">
                    <h2 className="font-medium">Beyond the allowance</h2>
                    <table className="w-full text-sm">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                <th className="py-2">Item</th>
                                <th>Credits</th>
                                <th>Price</th>
                                <th>Our cost</th>
                                <th>Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((i) => (
                                <tr key={i.item} className="border-t">
                                    <td className="py-2">
                                        {i.label}
                                        <div className="text-xs text-muted-foreground">
                                            {i.made_with}
                                        </div>
                                    </td>
                                    <td>{i.credits}</td>
                                    <td>{money(i.price_cents)}</td>
                                    <td>{money(i.cost_cents)}</td>
                                    <td>
                                        {i.margin_pct === null
                                            ? '—'
                                            : `${i.margin_pct}%`}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            </div>
        </>
    );
}
