import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

import { Button } from '@/components/ui/button';
import type { Reading } from '@/types/reading';

const SIZES: { key: Reading['text']; label: string }[] = [
    { key: 'standard', label: 'Standard' },
    { key: 'large', label: 'Large' },
    { key: 'largest', label: 'Largest' },
];

const save = (changes: Partial<Reading>) =>
    router.post('/settings/reading', changes, { preserveScroll: true });

/** Puts the person's easier-reading choices on <html> (also set by the server before the page draws). */
export function useEasierReading(reading: Reading | undefined) {
    useEffect(() => {
        const html = document.documentElement;
        const set = (name: string, value: string | null) =>
            value === null
                ? html.removeAttribute(name)
                : html.setAttribute(name, value);
        set(
            'data-text',
            reading && reading.text !== 'standard' ? reading.text : null,
        );
        set('data-contrast', reading?.contrast ? 'high' : null);
        set('data-motion', reading?.motion ? 'reduce' : null);
        set('data-links', reading?.links ? 'underline' : null);
    }, [reading]);
}

/** "Skip to main content", the first thing a keyboard user reaches. */
export function SkipLink() {
    return (
        <a className="skip-link" href="#main-content">
            Skip to main content
        </a>
    );
}

/** The header's quick text-size button: Standard → Large → Largest → Standard. */
export function TextSizeButton() {
    const reading = usePage().props.reading;
    if (!reading) {
        return null;
    }
    const at = SIZES.findIndex((s) => s.key === reading.text);
    const next = SIZES[(at + 1) % SIZES.length];

    return (
        <Button
            size="sm"
            variant="ghost"
            title="Text size"
            onClick={() => save({ text: next.key })}
        >
            <span aria-hidden className="font-semibold">
                A<span className="text-lg">A</span>
            </span>
            <span className="sr-only">
                Text size: {SIZES[at]?.label ?? 'Standard'}. Change to{' '}
                {next.label}
            </span>
        </Button>
    );
}

/** Settings › Appearance: the easier-reading choices, each one a big, plainly worded switch. */
export function EasierReadingPanel({ reading }: { reading: Reading }) {
    const toggles: {
        key: 'contrast' | 'motion' | 'links';
        title: string;
        hint: string;
    }[] = [
        {
            key: 'contrast',
            title: 'Stronger contrast',
            hint: 'Darker grey text and clearer borders.',
        },
        {
            key: 'links',
            title: 'Underline links',
            hint: 'So every link is easy to spot.',
        },
        {
            key: 'motion',
            title: 'Less movement',
            hint: 'Turns off sliding and fading.',
        },
    ];

    return (
        <section aria-labelledby="reading-title" className="grid gap-5">
            <div>
                <h2 id="reading-title" className="text-lg font-semibold">
                    Easier reading
                </h2>
                <p className="text-sm text-muted-foreground">
                    Changes only how RightAlly looks for you. Saved to your
                    account, so it follows you to any device.
                </p>
            </div>
            <fieldset className="grid gap-2">
                <legend className="mb-2 font-medium">Text size</legend>
                <div className="flex flex-wrap gap-3">
                    {SIZES.map((s, i) => (
                        <Button
                            key={s.key}
                            size="lg"
                            variant={
                                reading.text === s.key ? 'default' : 'outline'
                            }
                            aria-pressed={reading.text === s.key}
                            onClick={() => save({ text: s.key })}
                        >
                            <span style={{ fontSize: `${1 + i * 0.2}rem` }}>
                                {s.label}
                            </span>
                        </Button>
                    ))}
                </div>
            </fieldset>
            <div className="grid gap-3">
                {toggles.map((t) => (
                    <div
                        key={t.key}
                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                    >
                        <div>
                            <p className="font-medium">{t.title}</p>
                            <p className="text-sm text-muted-foreground">
                                {t.hint}
                            </p>
                        </div>
                        <Button
                            size="lg"
                            variant={reading[t.key] ? 'default' : 'outline'}
                            aria-pressed={reading[t.key]}
                            aria-label={`${t.title}: ${reading[t.key] ? 'on' : 'off'}`}
                            onClick={() => save({ [t.key]: !reading[t.key] })}
                        >
                            {reading[t.key] ? 'On' : 'Off'}
                        </Button>
                    </div>
                ))}
            </div>
        </section>
    );
}
