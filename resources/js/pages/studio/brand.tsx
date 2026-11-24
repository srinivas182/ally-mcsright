import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Colors = { primary: string; accent: string; ink: string; paper: string };

type Kit = {
    collection: string;
    colors: Colors;
    fonts: { heading: string; body: string };
    logo: string | null;
    headshot: string | null;
    cutout: string | null;
    details: Record<string, string>;
    languages: string[];
    locked: boolean;
};

type Brokerage = {
    collection: string | null;
    styles: string[];
    colors: Partial<Colors>;
    fonts: Partial<{ heading: string; body: string }>;
    details: Record<string, string>;
    logo: string | null;
    logo_dark: string | null;
    brand_lock: boolean;
};

const select = 'h-12 rounded-md border bg-background px-3';
const colorNames: Record<keyof Colors, string> = {
    primary: 'Main colour',
    accent: 'Accent',
    ink: 'Text',
    paper: 'Background',
};

/** Ally Studio brand kit (specs/grow/13): yours, and the brokerage's for owners. */
export default function StudioBrand({
    kit,
    collections,
    fonts,
    languages,
    is_owner,
    brokerage,
    cutout,
}: {
    kit: Kit;
    collections: Record<string, string>;
    fonts: string[];
    languages: Record<string, string>;
    is_owner: boolean;
    brokerage: Brokerage | null;
    cutout: boolean;
}) {
    const mine = useForm({
        for: 'me',
        headshot: null as File | null,
        collection: kit.collection,
        colors: kit.colors,
        fonts: kit.fonts,
        languages: kit.languages,
        details: {
            display_name: kit.details.display_name ?? '',
            title: kit.details.title ?? '',
            licence_line: kit.details.licence_line ?? '',
            phone: kit.details.phone ?? '',
            email: kit.details.email ?? '',
            tagline: kit.details.tagline ?? '',
            instagram: kit.details.instagram ?? '',
            video_bumpers: kit.details.video_bumpers ?? '',
        },
    });
    const firm = useForm({
        for: 'brokerage',
        logo: null as File | null,
        logo_dark: null as File | null,
        collection: brokerage?.collection ?? 'gallery',
        styles: brokerage?.styles ?? [],
        colors: { ...kit.colors, ...brokerage?.colors },
        fonts: { ...kit.fonts, ...brokerage?.fonts },
        languages: ['en'],
        brand_lock: brokerage?.brand_lock ?? false,
        details: { disclaimer: brokerage?.details.disclaimer ?? '' },
    });
    const errors = mine.errors as Record<string, string>;
    const photo = kit.cutout ?? kit.headshot;

    type Look = {
        collection: string;
        colors: Colors;
        fonts: { heading: string; body: string };
    };
    const look = (
        v: Look,
        set: <K extends keyof Look>(k: K, value: Look[K]) => void,
        locked: boolean,
    ) => (
        <div className="grid gap-3 sm:grid-cols-2">
            <label className="grid gap-1">
                Style
                <select
                    className={select}
                    disabled={locked}
                    value={v.collection}
                    onChange={(e) => set('collection', e.target.value)}
                >
                    {Object.entries(collections).map(([k, l]) => (
                        <option key={k} value={k}>
                            {l}
                        </option>
                    ))}
                </select>
            </label>
            {(['heading', 'body'] as const).map((which) => (
                <label key={which} className="grid gap-1">
                    {which === 'heading' ? 'Heading font' : 'Text font'}
                    <select
                        className={select}
                        disabled={locked}
                        value={v.fonts[which]}
                        onChange={(e) =>
                            set('fonts', {
                                ...v.fonts,
                                [which]: e.target.value,
                            })
                        }
                    >
                        {fonts.map((n) => (
                            <option key={n} value={n}>
                                {n}
                            </option>
                        ))}
                    </select>
                </label>
            ))}
            <div className="flex flex-wrap gap-3 sm:col-span-2">
                {(Object.keys(colorNames) as (keyof Colors)[]).map((c) => (
                    <label key={c} className="grid gap-1 text-sm">
                        {colorNames[c]}
                        <input
                            type="color"
                            className="h-12 w-20 rounded border"
                            disabled={locked}
                            value={v.colors[c]}
                            onChange={(e) =>
                                set('colors', {
                                    ...v.colors,
                                    [c]: e.target.value,
                                })
                            }
                        />
                    </label>
                ))}
            </div>
        </div>
    );

    return (
        <>
            <Head title="Brand kit" />
            <div className="grid max-w-3xl gap-6 p-4 text-base">
                <PageHeader
                    title="Brand kit"
                    description="Every Ally Studio design, video and caption uses this: your photo, your brand and your words."
                />

                <form
                    className="grid gap-4 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        mine.post('/studio/brand', {
                            forceFormData: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <h2 className="font-medium">You</h2>
                    <div className="flex items-center gap-4">
                        {photo && (
                            <img
                                src={photo}
                                alt="Your headshot"
                                className="size-24 rounded-full bg-muted object-cover"
                            />
                        )}
                        <label className="grid gap-1">
                            Headshot (at least 400 px; JPG, PNG or WebP)
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={(e) =>
                                    mine.setData(
                                        'headshot',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            <span className="text-sm text-muted-foreground">
                                {cutout
                                    ? 'The background is removed automatically.'
                                    : 'Use a photo on a plain background.'}
                            </span>
                            {errors.headshot && (
                                <span className="text-destructive">
                                    {errors.headshot}
                                </span>
                            )}
                        </label>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {(
                            [
                                ['display_name', 'Name on designs'],
                                [
                                    'title',
                                    'Title (e.g. Luxury Home Specialist)',
                                ],
                                [
                                    'licence_line',
                                    'Licence line (e.g. TX #0123456)',
                                ],
                                ['phone', 'Phone'],
                                ['email', 'Email'],
                                ['instagram', 'Instagram handle'],
                            ] as const
                        ).map(([k, label]) => (
                            <label key={k} className="grid gap-1">
                                {label}
                                <Input
                                    value={mine.data.details[k]}
                                    onChange={(e) =>
                                        mine.setData('details', {
                                            ...mine.data.details,
                                            [k]: e.target.value,
                                        })
                                    }
                                />
                            </label>
                        ))}
                        <label className="grid gap-1 sm:col-span-2">
                            Tagline
                            <Input
                                maxLength={120}
                                value={mine.data.details.tagline}
                                onChange={(e) =>
                                    mine.setData('details', {
                                        ...mine.data.details,
                                        tagline: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label className="flex items-center gap-2 sm:col-span-2">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={
                                    mine.data.details.video_bumpers === 'yes'
                                }
                                onChange={(e) =>
                                    mine.setData('details', {
                                        ...mine.data.details,
                                        video_bumpers: e.target.checked
                                            ? 'yes'
                                            : '',
                                    })
                                }
                            />
                            Start and end my videos with my brand (logo, name,
                            phone and licence line)
                        </label>
                    </div>
                    <fieldset className="flex gap-4">
                        <legend className="mb-1">Caption languages</legend>
                        {Object.entries(languages).map(([k, l]) => (
                            <label key={k} className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    className="size-5"
                                    checked={mine.data.languages.includes(k)}
                                    onChange={(e) =>
                                        mine.setData(
                                            'languages',
                                            e.target.checked
                                                ? [...mine.data.languages, k]
                                                : mine.data.languages.filter(
                                                      (x) => x !== k,
                                                  ),
                                        )
                                    }
                                />
                                {l}
                            </label>
                        ))}
                    </fieldset>
                    {kit.locked ? (
                        <p className="text-sm text-muted-foreground">
                            Your brokerage sets the style, colours and fonts.
                        </p>
                    ) : (
                        look(
                            mine.data,
                            (k, value) =>
                                mine.setData((d) => ({ ...d, [k]: value })),
                            false,
                        )
                    )}
                    <div>
                        <Button type="submit" disabled={mine.processing}>
                            Save my brand kit
                        </Button>
                    </div>
                </form>

                {is_owner && brokerage && (
                    <form
                        className="grid gap-4 rounded-lg border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            firm.post('/studio/brand', {
                                forceFormData: true,
                                preserveScroll: true,
                            });
                        }}
                    >
                        <h2 className="font-medium">The brokerage</h2>
                        <p className="text-sm text-muted-foreground">
                            Your logo shows on your website, your back office,
                            your emails and your designs. Use a wide logo, about
                            600 × 200 pixels (at least 200 pixels wide), as a
                            PNG with a see-through background (best), JPG or
                            WebP, up to 10 MB. Empty space around it is trimmed
                            and it is sized to fit each place automatically.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(
                                [
                                    [
                                        'logo',
                                        'Main logo',
                                        'For white or light backgrounds: your website header, emails and documents. Usually your full-colour logo.',
                                        brokerage.logo,
                                        'bg-white',
                                    ],
                                    [
                                        'logo_dark',
                                        'Logo for dark backgrounds (optional)',
                                        'A white or light version, for dark photos, dark website designs and video endings.',
                                        brokerage.logo_dark,
                                        'bg-slate-900',
                                    ],
                                ] as const
                            ).map(([k, label, hint, url, backdrop]) => (
                                <LogoPicker
                                    key={k}
                                    label={label}
                                    hint={hint}
                                    current={url}
                                    backdrop={backdrop}
                                    error={firm.errors[k]}
                                    onPick={(file) => firm.setData(k, file)}
                                />
                            ))}
                        </div>
                        {look(
                            firm.data,
                            (k, value) =>
                                firm.setData((d) => ({ ...d, [k]: value })),
                            false,
                        )}
                        <label className="grid gap-1">
                            Disclaimer on every design (optional)
                            <Input
                                maxLength={300}
                                value={firm.data.details.disclaimer}
                                onChange={(e) =>
                                    firm.setData('details', {
                                        disclaimer: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={firm.data.brand_lock}
                                onChange={(e) =>
                                    firm.setData('brand_lock', e.target.checked)
                                }
                            />
                            Agents use the brokerage's styles, colours and fonts
                            (brand lock)
                        </label>
                        {firm.data.brand_lock && (
                            <fieldset className="grid gap-2 sm:grid-cols-3">
                                <legend className="mb-1 text-sm">
                                    Styles agents may choose (none ticked: only
                                    the brokerage's style)
                                </legend>
                                {Object.entries(collections).map(([k, l]) => (
                                    <label
                                        key={k}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            checked={firm.data.styles.includes(
                                                k,
                                            )}
                                            onChange={(e) =>
                                                firm.setData(
                                                    'styles',
                                                    e.target.checked
                                                        ? [
                                                              ...firm.data
                                                                  .styles,
                                                              k,
                                                          ]
                                                        : firm.data.styles.filter(
                                                              (x) => x !== k,
                                                          ),
                                                )
                                            }
                                        />
                                        {l}
                                    </label>
                                ))}
                            </fieldset>
                        )}
                        <div>
                            <Button type="submit" disabled={firm.processing}>
                                Save the brokerage brand
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </>
    );
}

const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
const LOGO_MAX_BYTES = 10 * 1024 * 1024;

/**
 * UX1: a logo upload that says what works, shows the logo on the background it is for, and turns away the wrong kind
 * of file at once (before saving), with the reason in plain words.
 */
function LogoPicker({
    label,
    hint,
    current,
    backdrop,
    error,
    onPick,
}: {
    label: string;
    hint: string;
    current: string | null;
    backdrop: string;
    error?: string;
    onPick: (file: File | null) => void;
}) {
    const [preview, setPreview] = useState<string | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const shown = preview ?? current;

    const pick = (input: HTMLInputElement) => {
        const file = input.files?.[0] ?? null;
        setProblem(null);
        if (!file) {
            onPick(null);
            setPreview(null);
            return;
        }
        const reject = (why: string) => {
            setProblem(why);
            input.value = '';
            onPick(null);
            setPreview(null);
        };
        if (!LOGO_TYPES.includes(file.type)) {
            reject(
                `"${file.name}" is not a PNG, JPG or WebP image. Save your logo as a PNG (best) and try again.`,
            );
            return;
        }
        if (file.size > LOGO_MAX_BYTES) {
            reject('That file is larger than 10 MB. Use a smaller version.');
            return;
        }
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            if (img.naturalWidth < 200) {
                URL.revokeObjectURL(url);
                reject(
                    `That logo is only ${img.naturalWidth} pixels wide. Use one at least 200 pixels wide (about 600 × 200 works best).`,
                );
                return;
            }
            setPreview(url);
            onPick(file);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(
                'That image could not be opened. Try saving it again as a PNG.',
            );
        };
        img.src = url;
    };

    return (
        <div className="grid gap-2 rounded-lg border p-3">
            <p className="font-medium">{label}</p>
            <p className="text-sm text-muted-foreground">{hint}</p>
            <div
                className={`flex h-20 items-center justify-center rounded-md border ${backdrop}`}
            >
                {shown ? (
                    <img
                        src={shown}
                        alt={label}
                        className="max-h-14 max-w-[85%] object-contain"
                    />
                ) : (
                    <span
                        className={
                            backdrop === 'bg-white'
                                ? 'text-sm text-slate-500'
                                : 'text-sm text-slate-300'
                        }
                    >
                        No logo yet
                    </span>
                )}
            </div>
            <input
                type="file"
                aria-label={label}
                accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                onChange={(e) => pick(e.currentTarget)}
            />
            {(problem ?? error) && (
                <p className="text-sm text-destructive" role="alert">
                    {problem ?? error}
                </p>
            )}
        </div>
    );
}
