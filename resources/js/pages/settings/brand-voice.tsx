import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Voice = {
    tone: string;
    use_words: string[];
    avoid_words: string[];
    emojis: boolean;
    sign_off: string | null;
    spanish: 'usted' | 'tu';
    name_brokerage: boolean;
    approved_at: string | null;
};

type Guide = {
    tone: string;
    use_words: string[];
    avoid_words: string[];
    emojis: boolean;
    sign_off: string;
    colors: string[];
    fonts: string[];
    taglines: string[];
    disclaimer: string;
    file: string | null;
};

type Report = {
    checked: number;
    on_brand: number;
    percent: number | null;
    issues: { issue: string; count: number }[];
    features: { feature: string; checked: number; on_brand: number }[];
};

type Props = {
    voice: Voice | null;
    guide: Guide | null;
    tones: Record<string, string>;
    report: Report;
    brokerage: string;
};

const list = (s: string): string[] =>
    s
        .split(',')
        .map((w) => w.trim())
        .filter((w) => w !== '');

/**
 * BV1 (specs/core/08): upload the brand guide once; review what the AI read; approve the Brand Voice that every AI
 * text, email, post and page follows; and see how consistently AI writing stays on brand.
 */
export default function BrandVoicePage({
    voice,
    guide,
    tones,
    report,
    brokerage,
}: Props) {
    const start = guide ?? voice;
    const [useWords, setUseWords] = useState(
        (start?.use_words ?? []).join(', '),
    );
    const [avoidWords, setAvoidWords] = useState(
        (start?.avoid_words ?? []).join(', '),
    );
    const form = useForm({
        tone: start?.tone ?? 'warm',
        emojis: start?.emojis ?? false,
        sign_off: start?.sign_off ?? '',
        spanish: voice?.spanish ?? 'usted',
        name_brokerage: voice?.name_brokerage ?? true,
        apply_kit:
            guide !== null &&
            (guide.colors.length > 0 || guide.fonts.length > 0),
    });
    const upload = useForm<{ guide: File | null }>({ guide: null });

    // A newly read brand guide arrives as new props on the same page: show what was read in the form below.
    useEffect(() => {
        if (guide === null) {
            return;
        }
        setUseWords(guide.use_words.join(', '));
        setAvoidWords(guide.avoid_words.join(', '));
        form.setData((d) => ({
            ...d,
            tone: guide.tone,
            emojis: guide.emojis,
            sign_off: guide.sign_off,
            apply_kit: guide.colors.length > 0 || guide.fonts.length > 0,
        }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [guide]);

    return (
        <>
            <Head title="Brand voice" />
            <div className="mx-auto grid w-full max-w-3xl gap-8 p-4 text-base md:p-6">
                <PageHeader
                    title="Brand voice"
                    description={`How ${brokerage} sounds. Every AI text, email, listing description, post and web page follows it, in English and Spanish.`}
                />

                <section className="grid gap-3 rounded-lg border p-4">
                    <h2 className="text-lg font-semibold">
                        Upload your brand guide (optional)
                    </h2>
                    <p className="text-muted-foreground">
                        Upload the PDF once. The AI reads the tone, words to use
                        and avoid, colours, fonts and disclaimer. You check
                        everything before it is used. The file itself is not
                        kept.
                    </p>
                    <form
                        className="flex flex-wrap items-center gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            upload.post('/settings/brand-voice/guide', {
                                forceFormData: true,
                                preserveScroll: true,
                            });
                        }}
                    >
                        <input
                            type="file"
                            accept="application/pdf"
                            aria-label="Brand guide PDF"
                            onChange={(e) =>
                                upload.setData(
                                    'guide',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <Button
                            disabled={
                                upload.processing || upload.data.guide === null
                            }
                        >
                            Read my brand guide
                        </Button>
                    </form>
                    {upload.errors.guide && (
                        <p role="alert" className="text-destructive">
                            {upload.errors.guide}
                        </p>
                    )}
                    {guide && (
                        <div className="grid gap-1 rounded-md bg-muted/50 p-3 text-sm">
                            <p className="font-medium">
                                From {guide.file ?? 'your brand guide'} (filled
                                in below; change anything, then approve):
                            </p>
                            {guide.colors.length > 0 && (
                                <p className="flex flex-wrap items-center gap-2">
                                    Colours:
                                    {guide.colors.map((c) => (
                                        <span
                                            key={c}
                                            className="inline-flex items-center gap-1"
                                        >
                                            <span
                                                className="inline-block size-4 rounded border"
                                                style={{ background: c }}
                                                aria-hidden="true"
                                            />
                                            {c}
                                        </span>
                                    ))}
                                </p>
                            )}
                            {guide.fonts.length > 0 && (
                                <p>Fonts: {guide.fonts.join(', ')}</p>
                            )}
                            {guide.taglines.length > 0 && (
                                <p>Taglines: {guide.taglines.join(' · ')}</p>
                            )}
                            {guide.disclaimer !== '' && (
                                <p>Disclaimer: {guide.disclaimer}</p>
                            )}
                        </div>
                    )}
                </section>

                <form
                    className="grid gap-4 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({
                            ...d,
                            use_words: list(useWords),
                            avoid_words: list(avoidWords),
                            colors: guide?.colors ?? [],
                            fonts: guide?.fonts ?? [],
                            disclaimer: guide?.disclaimer ?? null,
                        }));
                        form.post('/settings/brand-voice', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="grow text-lg font-semibold">
                            Your brand voice
                        </h2>
                        {voice?.approved_at ? (
                            <Badge>In use</Badge>
                        ) : (
                            <Badge variant="outline">Not approved yet</Badge>
                        )}
                    </div>
                    <fieldset className="grid gap-2">
                        <legend className="font-medium">Tone</legend>
                        {Object.entries(tones).map(([k, v]) => (
                            <label key={k} className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    className="size-5"
                                    name="tone"
                                    checked={form.data.tone === k}
                                    onChange={() => form.setData('tone', k)}
                                />
                                {v}
                            </label>
                        ))}
                    </fieldset>
                    <label className="grid gap-1">
                        Words to use (separate with commas)
                        <Input
                            value={useWords}
                            onChange={(e) => setUseWords(e.target.value)}
                            placeholder="home, neighbours, local"
                        />
                    </label>
                    <label className="grid gap-1">
                        Words never to use (separate with commas)
                        <Input
                            value={avoidWords}
                            onChange={(e) => setAvoidWords(e.target.value)}
                            placeholder="cheap, deal of a lifetime"
                        />
                    </label>
                    <label className="grid gap-1">
                        Sign-off (optional)
                        <Input
                            value={form.data.sign_off ?? ''}
                            maxLength={120}
                            onChange={(e) =>
                                form.setData('sign_off', e.target.value)
                            }
                        />
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-5"
                            checked={form.data.emojis}
                            onChange={(e) =>
                                form.setData('emojis', e.target.checked)
                            }
                        />
                        Emojis are fine
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-5"
                            checked={form.data.name_brokerage}
                            onChange={(e) =>
                                form.setData('name_brokerage', e.target.checked)
                            }
                        />
                        Texts and social posts always name {brokerage}
                    </label>
                    <label className="grid max-w-xs gap-1">
                        In Spanish, address people as
                        <select
                            className="h-10 rounded-md border bg-background px-3"
                            value={form.data.spanish}
                            onChange={(e) =>
                                form.setData(
                                    'spanish',
                                    e.target.value as 'usted' | 'tu',
                                )
                            }
                        >
                            <option value="usted">usted (more formal)</option>
                            <option value="tu">tú (more familiar)</option>
                        </select>
                    </label>
                    {guide &&
                        (guide.colors.length > 0 || guide.fonts.length > 0) && (
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    className="size-5"
                                    checked={form.data.apply_kit}
                                    onChange={(e) =>
                                        form.setData(
                                            'apply_kit',
                                            e.target.checked,
                                        )
                                    }
                                />
                                Also use the guide's colours, fonts and
                                disclaimer in Ally Studio designs
                            </label>
                        )}
                    <Button
                        className="justify-self-start"
                        disabled={form.processing}
                    >
                        Approve and use
                    </Button>
                    {Object.values(form.errors)[0] && (
                        <p role="alert" className="text-destructive">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                </form>

                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">
                        How consistent is AI writing? (last 30 days)
                    </h2>
                    {report.checked === 0 ? (
                        <p className="text-muted-foreground">
                            Nothing checked yet. The report fills in once your
                            voice is approved and AI writes for you.
                        </p>
                    ) : (
                        <>
                            <p className="text-lg">
                                <strong>{report.percent}%</strong> on brand (
                                {report.on_brand} of {report.checked} pieces).
                            </p>
                            {report.issues.length > 0 && (
                                <ul className="list-disc pl-6">
                                    {report.issues.map((i) => (
                                        <li key={i.issue}>
                                            {i.issue}: {i.count}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <ul className="divide-y rounded-lg border text-sm">
                                {report.features.map((f) => (
                                    <li
                                        key={f.feature}
                                        className="flex justify-between p-2"
                                    >
                                        <span>{f.feature}</span>
                                        <span>
                                            {f.on_brand} of {f.checked} on brand
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                    <Button
                        variant="outline"
                        className="justify-self-start"
                        onClick={() => router.reload()}
                    >
                        Refresh
                    </Button>
                </section>
            </div>
        </>
    );
}
