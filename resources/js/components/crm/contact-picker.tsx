import { useEffect, useState } from 'react';

import { Input } from '@/components/ui/input';

type Found = { ulid: string; name: string; email: string | null };

/** Type a name or email to find one of your contacts. */
export function ContactPicker({
    label,
    onPick,
}: {
    label: string;
    onPick: (contact: Found) => void;
}) {
    const [q, setQ] = useState('');
    const [found, setFound] = useState<Found[]>([]);

    useEffect(() => {
        if (q.trim().length < 2) {
            setFound([]);
            return;
        }
        const timer = setTimeout(() => {
            void fetch(`/contacts/lookup?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((r) => r.json())
                .then((d: { contacts: Found[] }) => setFound(d.contacts))
                .catch(() => setFound([]));
        }, 250);
        return () => clearTimeout(timer);
    }, [q]);

    return (
        <div className="relative grid gap-1">
            <Input
                aria-label={label}
                placeholder={label}
                value={q}
                onChange={(e) => setQ(e.target.value)}
            />
            {found.length > 0 && (
                <ul
                    role="listbox"
                    className="absolute top-full z-10 mt-1 w-full rounded-md border bg-popover shadow"
                >
                    {found.map((c) => (
                        <li key={c.ulid}>
                            <button
                                type="button"
                                role="option"
                                aria-selected="false"
                                className="w-full px-3 py-2 text-left text-sm hover:bg-muted"
                                onClick={() => {
                                    onPick(c);
                                    setQ('');
                                    setFound([]);
                                }}
                            >
                                {c.name}{' '}
                                {c.email && (
                                    <span className="text-muted-foreground">
                                        · {c.email}
                                    </span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
