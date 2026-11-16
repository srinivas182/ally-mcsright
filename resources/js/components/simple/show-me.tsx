import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';

import { TOURS } from './tours';

/** "Show me how" (SM1): walks through the page, one highlighted part at a time. */
export function ShowMe() {
    const page = usePage();
    const simple = page.props.simple;
    const locale = simple?.locale ?? 'en';
    const words = simple?.words ?? {};
    const steps = (TOURS[page.component] ?? []).filter(
        (s) =>
            typeof document === 'undefined' ||
            document.querySelector(`[data-tour="${s.target}"]`) !== null,
    );
    const [at, setAt] = useState<number | null>(null);

    useEffect(() => {
        document
            .querySelectorAll('[data-tour-active]')
            .forEach((el) => el.removeAttribute('data-tour-active'));
        if (at === null) {
            return;
        }
        const el = document.querySelector(`[data-tour="${steps[at]?.target}"]`);
        el?.setAttribute('data-tour-active', 'true');
        el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, [at, steps]);

    if (steps.length === 0) {
        return null;
    }

    return (
        <>
            <Button size="sm" variant="outline" onClick={() => setAt(0)}>
                {words.show_me ?? 'Show me how'}
            </Button>
            {at !== null && steps[at] && (
                <div
                    role="dialog"
                    aria-live="polite"
                    className="fixed inset-x-4 bottom-24 z-50 mx-auto grid max-w-md gap-3 rounded-xl border bg-background p-4 text-base shadow-xl"
                >
                    <p>{locale === 'es' ? steps[at].es : steps[at].en}</p>
                    <span className="flex items-center gap-2">
                        <span className="text-sm text-muted-foreground">
                            {at + 1} / {steps.length}
                        </span>
                        <Button
                            className="ml-auto"
                            onClick={() =>
                                setAt(at + 1 < steps.length ? at + 1 : null)
                            }
                        >
                            {at + 1 < steps.length
                                ? (words.next ?? 'Next')
                                : (words.done ?? 'Done')}
                        </Button>
                    </span>
                </div>
            )}
        </>
    );
}
