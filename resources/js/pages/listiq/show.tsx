import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Listing = {
    ulid: string;
    street: string;
    city: string | null;
    facts: Record<string, string | number | null> | null;
    notes: string | null;
    showing: string | null;
    manual: Record<string, boolean>;
    descriptions: { style: string; text: string }[];
    description: string | null;
    description_es: string | null;
    remarks_limit: number;
    status: string;
    flags: { phrase: string; instead: string }[];
};
type Item = {
    key: string;
    label: string;
    done: boolean;
    auto: boolean;
    hint: string;
};
type Room = {
    key: string;
    label: string;
    tip: string;
    suggested: number;
    count: number;
};
type Marketing = {
    in_mls: boolean;
    page_url: string | null;
    codes: {
        code: string;
        kind: string;
        label: string;
        scans: number;
        leads: number;
        url: string | null;
    }[];
    kinds: Record<string, string>;
    activity: {
        views: number;
        scans: number;
        code_leads: number;
        open_houses: number;
        visitors: number;
    };
    floor_plan: string | null;
    open_houses: {
        ulid: string;
        starts: string;
        ended: boolean;
        visitors: number;
    }[];
};
type Photo = {
    ulid: string;
    kind: string;
    room: string;
    room_label: string;
    caption: string | null;
    use: boolean;
    status: string;
    error: string | null;
    url: string | null;
    style: string | null;
};

/** One listing in List-IQ (LI1): checklist, guided shoot, photos (order, captions, enhance, stage), description. */
export default function ListIqShow({
    listing: l,
    checklist,
    rooms,
    tips,
    photos,
    styles,
    can,
    marketing: m,
}: {
    listing: Listing;
    checklist: Item[];
    rooms: Room[];
    tips: string[];
    photos: Photo[];
    styles: string[];
    can: { stage: boolean; enhance: boolean };
    marketing: Marketing;
}) {
    const opts = { preserveScroll: true };
    const errors = usePage().props.errors as Record<string, string>;
    const base = `/list-iq/${l.ulid}`;
    const patch = (data: Record<string, FormDataConvertible>) =>
        router.patch(base, data, opts);
    const [room, setRoom] = useState(
        rooms.find((r) => r.count < r.suggested)?.key ?? 'front',
    );
    const [notes, setNotes] = useState(l.notes ?? '');
    const [showing, setShowing] = useState(l.showing ?? '');
    const [text, setText] = useState(l.description ?? '');
    const [es, setEs] = useState(l.description_es ?? '');
    const [style, setStyle] = useState(styles[0] ?? 'modern');
    const [kind, setKind] = useState('sign');
    const [oh, setOh] = useState({ date: '', from: '13:00', to: '16:00' });
    const done = checklist.filter((i) => i.done).length;
    const busy = photos.some(
        (p) => p.status === 'queued' || p.status === 'processing',
    );

    useEffect(() => {
        if (!busy) return;
        const t = setInterval(
            () => router.reload({ only: ['photos', 'checklist'] }),
            8000,
        );
        return () => clearInterval(t);
    }, [busy]);

    return (
        <>
            <Head title={`List-IQ · ${l.street}`} />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title={l.street}
                    description={
                        l.status === 'ready'
                            ? 'Ready to go live.'
                            : `${done} of ${checklist.length} done.`
                    }
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/list-iq">All listings</Link>
                            </Button>
                            <Button asChild>
                                <a href={`${base}/package`}>
                                    Download the MLS package
                                </a>
                            </Button>
                        </div>
                    }
                />

                <section
                    className="grid gap-2 rounded-lg border p-4"
                    aria-labelledby="ready"
                >
                    <h2 id="ready" className="font-semibold">
                        Checklist
                    </h2>
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {checklist.map((i) => (
                            <li key={i.key} className="flex items-start gap-2">
                                {i.auto ? (
                                    <span
                                        aria-hidden
                                        className={`mt-1 inline-block size-4 rounded-full ${i.done ? 'bg-emerald-500' : 'border-2 border-muted-foreground'}`}
                                    />
                                ) : (
                                    <input
                                        type="checkbox"
                                        className="mt-1 size-5"
                                        checked={i.done}
                                        onChange={(e) =>
                                            patch({
                                                manual: {
                                                    [i.key]: e.target.checked,
                                                },
                                            })
                                        }
                                        aria-label={i.label}
                                    />
                                )}
                                <span>
                                    {i.label}
                                    <span className="block text-sm text-muted-foreground">
                                        {i.hint}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                <section
                    className="grid gap-3 rounded-lg border p-4"
                    aria-labelledby="shoot"
                >
                    <h2 id="shoot" className="font-semibold">
                        Photo shoot
                    </h2>
                    <ul className="list-disc pl-5 text-sm text-muted-foreground">
                        {tips.map((t) => (
                            <li key={t}>{t}</li>
                        ))}
                    </ul>
                    <div
                        className="flex flex-wrap gap-2"
                        role="radiogroup"
                        aria-label="Room"
                    >
                        {rooms.map((r) => (
                            <Button
                                key={r.key}
                                type="button"
                                size="sm"
                                variant={room === r.key ? 'default' : 'outline'}
                                aria-pressed={room === r.key}
                                onClick={() => setRoom(r.key)}
                            >
                                {r.label}{' '}
                                {r.count > 0
                                    ? `(${r.count})`
                                    : r.suggested > 0
                                      ? ''
                                      : ''}
                            </Button>
                        ))}
                    </div>
                    <p className="text-sm">
                        {rooms.find((r) => r.key === room)?.tip}
                    </p>
                    <label className="grid gap-1 text-sm">
                        Add photos of this room
                        <input
                            type="file"
                            accept="image/*"
                            multiple
                            capture="environment"
                            onChange={(e) => {
                                const files = Array.from(e.target.files ?? []);
                                if (files.length > 0)
                                    router.post(
                                        `${base}/photos`,
                                        { room, photos: files },
                                        { ...opts, forceFormData: true },
                                    );
                            }}
                        />
                    </label>
                    {(errors.photos || errors.room) && (
                        <p className="text-sm text-red-700">
                            {errors.photos ?? errors.room}
                        </p>
                    )}
                </section>

                {photos.length > 0 && (
                    <section className="grid gap-3" aria-labelledby="photos">
                        <div className="flex flex-wrap items-end justify-between gap-2">
                            <h2 id="photos" className="font-semibold">
                                Photos, in order
                            </h2>
                            {can.stage && (
                                <label className="grid gap-1 text-sm">
                                    Staging style
                                    <select
                                        className="h-9 rounded-md border bg-background px-2"
                                        value={style}
                                        onChange={(e) =>
                                            setStyle(e.target.value)
                                        }
                                    >
                                        {styles.map((s) => (
                                            <option key={s} value={s}>
                                                {s}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                        </div>
                        {errors.photo && (
                            <p className="text-sm text-red-700">
                                {errors.photo}
                            </p>
                        )}
                        <ol className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {photos.map((p, i) => (
                                <li
                                    key={p.ulid}
                                    className={`grid gap-2 rounded-lg border p-2 ${p.use ? '' : 'opacity-60'}`}
                                >
                                    {p.url ? (
                                        <img
                                            src={p.url}
                                            alt={p.caption ?? p.room_label}
                                            className="aspect-[3/2] w-full rounded object-cover"
                                            loading="lazy"
                                        />
                                    ) : (
                                        <div className="grid aspect-[3/2] place-items-center rounded bg-muted text-sm">
                                            {p.status === 'failed'
                                                ? (p.error ?? 'Failed')
                                                : 'Working on it…'}
                                        </div>
                                    )}
                                    <div className="flex flex-wrap items-center gap-1 text-xs">
                                        <span>#{i + 1}</span>
                                        {p.kind === 'staged' && (
                                            <Badge>Virtually staged</Badge>
                                        )}
                                        {p.kind === 'enhanced' && (
                                            <Badge variant="outline">
                                                Enhanced
                                            </Badge>
                                        )}
                                        {!p.use && (
                                            <Badge variant="outline">
                                                Not used
                                            </Badge>
                                        )}
                                    </div>
                                    <Input
                                        aria-label="Caption"
                                        defaultValue={p.caption ?? ''}
                                        onBlur={(e) =>
                                            e.target.value !==
                                                (p.caption ?? '') &&
                                            router.patch(
                                                `${base}/photos/${p.ulid}`,
                                                { caption: e.target.value },
                                                opts,
                                            )
                                        }
                                    />
                                    <div className="flex flex-wrap gap-1">
                                        {i > 0 && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                aria-label="Move earlier"
                                                onClick={() => {
                                                    const order = photos.map(
                                                        (x) => x.ulid,
                                                    );
                                                    [order[i - 1], order[i]] = [
                                                        order[i],
                                                        order[i - 1],
                                                    ];
                                                    router.post(
                                                        `${base}/photos/order`,
                                                        { order },
                                                        opts,
                                                    );
                                                }}
                                            >
                                                ↑
                                            </Button>
                                        )}
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.patch(
                                                    `${base}/photos/${p.ulid}`,
                                                    { use: !p.use },
                                                    opts,
                                                )
                                            }
                                        >
                                            {p.use ? 'Leave out' : 'Use'}
                                        </Button>
                                        {p.kind === 'original' &&
                                            p.status === 'ready' &&
                                            can.enhance && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.post(
                                                            `${base}/photos/${p.ulid}/enhance`,
                                                            {},
                                                            opts,
                                                        )
                                                    }
                                                >
                                                    Enhance
                                                </Button>
                                            )}
                                        {p.kind === 'original' &&
                                            p.status === 'ready' &&
                                            can.stage && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.post(
                                                            `${base}/photos/${p.ulid}/stage`,
                                                            { style },
                                                            opts,
                                                        )
                                                    }
                                                >
                                                    Stage
                                                </Button>
                                            )}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </section>
                )}

                <section
                    className="grid gap-3 rounded-lg border p-4"
                    aria-labelledby="marketing"
                >
                    <h2 id="marketing" className="font-semibold">
                        Marketing
                    </h2>
                    <label className="flex items-start gap-2">
                        <input
                            type="checkbox"
                            className="mt-1 size-5"
                            checked={m.in_mls}
                            onChange={(e) =>
                                router.post(
                                    `${base}/mls`,
                                    { on: e.target.checked },
                                    opts,
                                )
                            }
                        />
                        <span>
                            It's in the MLS
                            <span className="block text-sm text-muted-foreground">
                                The listing's page, QR codes and flyer go public
                                only then (the MLS Clear Cooperation rule:
                                public marketing must reach the MLS within one
                                business day).
                            </span>
                        </span>
                    </label>
                    {m.in_mls && m.page_url && (
                        <p className="text-sm">
                            Listing page:{' '}
                            <a
                                className="break-all underline"
                                href={m.page_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                {m.page_url}
                            </a>
                        </p>
                    )}
                    <ul className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
                        {(
                            [
                                [m.activity.views, 'page views'],
                                [m.activity.scans, 'QR scans'],
                                [m.activity.code_leads, 'leads from codes'],
                                [m.activity.open_houses, 'open houses'],
                                [m.activity.visitors, 'visitors'],
                            ] as const
                        ).map(([n, label]) => (
                            <li key={label} className="rounded border p-2">
                                <strong className="block text-lg">{n}</strong>
                                {label}
                            </li>
                        ))}
                    </ul>
                    <div className="grid gap-2">
                        <h3 className="text-sm font-semibold">QR codes</h3>
                        <div className="flex flex-wrap items-end gap-2">
                            <select
                                className="h-10 rounded-md border bg-background px-3"
                                aria-label="Where the code goes"
                                value={kind}
                                onChange={(e) => setKind(e.target.value)}
                            >
                                {Object.entries(m.kinds).map(([k, label]) => (
                                    <option key={k} value={k}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(`${base}/codes`, { kind }, opts)
                                }
                            >
                                New code
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={`${base}/flyer`}>Flyer (PDF)</a>
                            </Button>
                        </div>
                        {m.codes.length > 0 && (
                            <ul className="grid gap-1 text-sm">
                                {m.codes.map((c) => (
                                    <li
                                        key={c.code}
                                        className="flex flex-wrap items-center gap-2"
                                    >
                                        <span className="min-w-0 flex-1">
                                            {c.label}: {c.scans} scans,{' '}
                                            {c.leads} leads
                                        </span>
                                        {c.url && (
                                            <a
                                                className="underline"
                                                href={`${base}/codes/${c.code}`}
                                            >
                                                Download QR
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    <div className="grid gap-2">
                        <h3 className="text-sm font-semibold">Open houses</h3>
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.post(`${base}/open-houses`, oh, opts);
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Date
                                <Input
                                    type="date"
                                    value={oh.date}
                                    onChange={(e) =>
                                        setOh({ ...oh, date: e.target.value })
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                From
                                <Input
                                    type="time"
                                    value={oh.from}
                                    onChange={(e) =>
                                        setOh({ ...oh, from: e.target.value })
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                To
                                <Input
                                    type="time"
                                    value={oh.to}
                                    onChange={(e) =>
                                        setOh({ ...oh, to: e.target.value })
                                    }
                                />
                            </label>
                            <Button variant="outline">Add</Button>
                        </form>
                        {(errors.starts_at || errors.ends_at) && (
                            <p className="text-sm text-red-700">
                                {errors.starts_at ?? errors.ends_at}
                            </p>
                        )}
                        <ul className="grid gap-1 text-sm">
                            {m.open_houses.map((o) => (
                                <li
                                    key={o.ulid}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <span className="min-w-0 flex-1">
                                        {o.starts} · {o.visitors} visitors
                                        {o.ended ? ' · ended' : ''}
                                    </span>
                                    {!o.ended && (
                                        <Button size="sm" asChild>
                                            <Link
                                                href={`${base}/open-houses/${o.ulid}`}
                                            >
                                                Open the sign-in kiosk
                                            </Link>
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                    <label className="grid gap-1 text-sm">
                        Floor plan (PDF or image from your floor-plan app)
                        <input
                            type="file"
                            accept=".pdf,image/*"
                            onChange={(e) => {
                                const f = e.target.files?.[0];
                                if (f)
                                    router.post(
                                        `${base}/floor-plan`,
                                        { floor_plan: f },
                                        { ...opts, forceFormData: true },
                                    );
                            }}
                        />
                    </label>
                    {m.floor_plan && (
                        <a
                            className="text-sm underline"
                            href={m.floor_plan}
                            target="_blank"
                            rel="noreferrer"
                        >
                            See the floor plan
                        </a>
                    )}
                    {errors.floor_plan && (
                        <p className="text-sm text-red-700">
                            {errors.floor_plan}
                        </p>
                    )}
                </section>

                <section
                    className="grid gap-3 rounded-lg border p-4"
                    aria-labelledby="desc"
                >
                    <h2 id="desc" className="font-semibold">
                        Description
                    </h2>
                    <label className="grid gap-1 text-sm">
                        What's special about this home (your notes; the AI uses
                        only these and the facts)
                        <Textarea
                            rows={3}
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            onBlur={() =>
                                notes !== (l.notes ?? '') && patch({ notes })
                            }
                        />
                    </label>
                    <Button
                        className="justify-self-start"
                        variant="outline"
                        onClick={() =>
                            router.post(`${base}/describe`, {}, opts)
                        }
                    >
                        {l.descriptions.length > 0
                            ? 'Write three new drafts'
                            : 'Write three drafts for me'}
                    </Button>
                    {errors.notes && (
                        <p className="text-sm text-red-700">{errors.notes}</p>
                    )}
                    {l.descriptions.length > 0 && (
                        <div className="grid gap-2 lg:grid-cols-3">
                            {l.descriptions.map((d) => (
                                <div
                                    key={d.style}
                                    className="grid gap-2 rounded border p-3 text-sm"
                                >
                                    <strong className="capitalize">
                                        {d.style}
                                    </strong>
                                    <p>{d.text}</p>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="justify-self-start"
                                        onClick={() => setText(d.text)}
                                    >
                                        Use this one
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                    <label className="grid gap-1 text-sm">
                        The description ({text.length} of {l.remarks_limit}{' '}
                        characters)
                        <Textarea
                            rows={6}
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                        />
                    </label>
                    {l.flags.length > 0 && (
                        <ul className="rounded border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-950/30">
                            {l.flags.map((f) => (
                                <li key={f.phrase}>
                                    Fair Housing: “{f.phrase}”. Instead,{' '}
                                    {f.instead}.
                                </li>
                            ))}
                        </ul>
                    )}
                    {errors.description && (
                        <p className="text-sm text-red-700">
                            {errors.description}
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            onClick={() => patch({ description: text })}
                            disabled={text.length > l.remarks_limit}
                        >
                            Save description
                        </Button>
                        {l.description && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(`${base}/translate`, {}, opts)
                                }
                            >
                                Spanish version
                            </Button>
                        )}
                    </div>
                    {l.description_es !== null && (
                        <label className="grid gap-1 text-sm">
                            Spanish
                            <Textarea
                                rows={5}
                                value={es}
                                onChange={(e) => setEs(e.target.value)}
                                onBlur={() =>
                                    es !== l.description_es &&
                                    patch({ description_es: es })
                                }
                            />
                        </label>
                    )}
                </section>

                <section
                    className="grid gap-2 rounded-lg border p-4"
                    aria-labelledby="showing"
                >
                    <h2 id="showing" className="font-semibold">
                        Showing instructions
                    </h2>
                    <Textarea
                        rows={3}
                        aria-labelledby="showing"
                        placeholder="Lockbox on the front door; 2 hours' notice; dog in the yard."
                        value={showing}
                        onChange={(e) => setShowing(e.target.value)}
                        onBlur={() =>
                            showing !== (l.showing ?? '') && patch({ showing })
                        }
                    />
                </section>
            </div>
        </>
    );
}
