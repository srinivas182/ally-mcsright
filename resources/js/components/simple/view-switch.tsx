import { router, usePage } from '@inertiajs/react';

import { Button } from '@/components/ui/button';

/** Simple or full view, and (in simple view) English or Spanish (SM1). */
export function ViewSwitch() {
    const simple = usePage().props.simple;
    if (!simple) {
        return null;
    }
    const w = simple.words;
    const on = simple.mode === 'simple';

    return (
        <span className="flex items-center gap-1" data-tour="view">
            {on && (
                <select
                    aria-label={w.language}
                    className="h-8 rounded border bg-background px-2 text-sm"
                    value={simple.locale}
                    onChange={(e) =>
                        router.post(
                            '/settings/view',
                            { locale: e.target.value },
                            { preserveScroll: true },
                        )
                    }
                >
                    {Object.entries(simple.locales).map(([k, l]) => (
                        <option key={k} value={k}>
                            {l}
                        </option>
                    ))}
                </select>
            )}
            <Button
                size="sm"
                variant="ghost"
                onClick={() =>
                    router.post('/settings/view', {
                        mode: on ? 'full' : 'simple',
                    })
                }
            >
                {on ? w.full : w.simple}
            </Button>
        </span>
    );
}

/** The first-visit question: simple or full? (shown until they choose). */
export function ChooseView() {
    const page = usePage();
    const simple = page.props.simple;
    if (
        !simple ||
        simple.mode !== null ||
        !['dashboard', 'agent/home'].includes(page.component)
    ) {
        return null;
    }
    const w = simple.words;

    return (
        <section
            className="mx-4 mt-4 grid gap-3 rounded-xl border-2 border-primary p-5 text-base md:mx-6"
            aria-label={w.choose_title}
        >
            <p className="text-lg font-semibold">{w.choose_title}</p>
            <p>{w.choose_intro}</p>
            <span className="flex flex-wrap gap-3">
                <Button
                    size="lg"
                    onClick={() =>
                        router.post('/settings/view', { mode: 'simple' })
                    }
                >
                    {w.choose_simple}
                </Button>
                <Button
                    size="lg"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            '/settings/view',
                            { mode: 'full' },
                            { preserveScroll: true },
                        )
                    }
                >
                    {w.choose_full}
                </Button>
            </span>
        </section>
    );
}

/** In simple view, a big "Talk" button on every page. */
export function TalkButton() {
    const simple = usePage().props.simple;
    if (simple?.mode !== 'simple') {
        return null;
    }

    return (
        <Button
            size="lg"
            className="fixed bottom-24 left-4 z-40 h-14 rounded-full px-6 text-base shadow-lg md:bottom-6"
            onClick={() => window.dispatchEvent(new Event('assistant:open'))}
        >
            🎙 {simple.words.talk}
        </Button>
    );
}
