import { useState } from 'react';

import { Button } from '@/components/ui/button';

/**
 * "Why can I see this?" (specs/core/03, R1): asks the server which rule gives
 * this person access to the record and shows the reasons in plain English.
 */
export function WhyCanISee({ url }: { url: string }) {
    const [reasons, setReasons] = useState<string[] | null>(null);
    const [busy, setBusy] = useState(false);

    const load = async () => {
        if (reasons) {
            setReasons(null);
            return;
        }
        setBusy(true);
        try {
            const res = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const body = (await res.json()) as { reasons?: string[] };
            setReasons(body.reasons ?? []);
        } catch {
            setReasons(['We could not check this just now. Please try again.']);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="grid gap-1 text-sm">
            <Button
                variant="link"
                size="sm"
                className="h-auto justify-start p-0"
                aria-expanded={reasons !== null}
                disabled={busy}
                onClick={() => void load()}
            >
                Why can I see this?
            </Button>
            {reasons && (
                <ul
                    className="list-disc rounded-md bg-muted/40 py-2 pr-3 pl-6 text-muted-foreground"
                    aria-live="polite"
                >
                    {reasons.map((r) => (
                        <li key={r}>{r}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}
