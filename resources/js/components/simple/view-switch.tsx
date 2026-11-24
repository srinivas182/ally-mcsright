import { router, usePage } from '@inertiajs/react';
import { MicIcon } from 'lucide-react';

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

/**
 * A microphone button on every page (SX4): tap it and say what you need; Your AI Assistant opens and listens. In
 * simple view it is the big "Talk" button; in full view a round button with the same job.
 */
export function TalkButton() {
    const { simple, tenant } = usePage().props;
    const listen = () => window.dispatchEvent(new Event('assistant:listen'));

    // UI3: HQ staff talk to the assistant from inside it; one floating button is enough there.
    if (tenant?.isHq) {
        return null;
    }

    // MN3: in the full view, talking and typing share one button (Your AI Assistant, with its microphone).
    if (simple?.mode !== 'simple') {
        return null;
    }

    return (
        <Button
            size="lg"
            className="fixed bottom-24 left-4 z-40 h-14 rounded-full px-6 text-base shadow-lg md:right-4 md:bottom-[4.25rem] md:left-auto"
            onClick={listen}
        >
            <MicIcon className="size-6" aria-hidden="true" />{' '}
            {simple.words.talk}
        </Button>
    );
}
