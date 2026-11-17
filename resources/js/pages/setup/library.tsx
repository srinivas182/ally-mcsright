import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Item = {
    key: string;
    kind: string;
    title: string;
    description: string;
    language: 'en' | 'es';
    version: number;
    status: 'available' | 'installed' | 'update' | 'changed' | 'removed';
    preview: string[];
};

type Props = { items: Item[]; kinds: Record<string, string> };

const STATUS: Record<Item['status'], string> = {
    available: '',
    installed: 'Installed',
    update: 'New version available',
    changed: 'You changed your copy',
    removed: 'You removed it',
};

/** SU3: proven plans, follow-ups and messages, installed in one click and kept up to date without overwriting changes. */
export default function SetupLibrary({ items, kinds }: Props) {
    const [language, setLanguage] = useState<'all' | 'en' | 'es'>('all');
    const shown = items.filter(
        (i) => language === 'all' || i.language === language,
    );
    const install = (key: string, copy = false) =>
        router.post(`/setup/library/${key}`, copy ? { copy: 1 } : {}, {
            preserveScroll: true,
        });

    return (
        <>
            <Head title="Ready-made library" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Ready-made library"
                    description="Proven commission plans, follow-up plans and messages, ready to use. Install with one click. When we improve an item, you can take the update, and we never overwrite anything you've changed."
                    actions={
                        <div
                            className="flex gap-2"
                            role="group"
                            aria-label="Language"
                        >
                            {(['all', 'en', 'es'] as const).map((l) => (
                                <Button
                                    key={l}
                                    variant={
                                        language === l ? 'default' : 'outline'
                                    }
                                    aria-pressed={language === l}
                                    onClick={() => setLanguage(l)}
                                >
                                    {l === 'all'
                                        ? 'All'
                                        : l === 'en'
                                          ? 'English'
                                          : 'Español'}
                                </Button>
                            ))}
                        </div>
                    }
                />
                {Object.entries(kinds).map(([kind, label]) => (
                    <section
                        key={kind}
                        aria-labelledby={`k-${kind}`}
                        className="grid gap-3"
                    >
                        <h2 id={`k-${kind}`} className="text-xl font-semibold">
                            {label}
                        </h2>
                        <ul className="grid gap-3 md:grid-cols-2">
                            {shown
                                .filter((i) => i.kind === kind)
                                .map((i) => (
                                    <li
                                        key={i.key}
                                        className="grid content-start gap-2 rounded-lg border p-4"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <p className="text-lg font-medium">
                                                {i.title}
                                            </p>
                                            {STATUS[i.status] && (
                                                <Badge
                                                    variant={
                                                        i.status === 'update'
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {STATUS[i.status]}
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {i.description}
                                        </p>
                                        <details>
                                            <summary className="cursor-pointer text-sm underline">
                                                What's in it
                                            </summary>
                                            <ul className="mt-2 grid gap-1 text-sm whitespace-pre-line">
                                                {i.preview.map((p, n) => (
                                                    <li key={n}>{p}</li>
                                                ))}
                                            </ul>
                                        </details>
                                        <div className="flex flex-wrap gap-2">
                                            {(i.status === 'available' ||
                                                i.status === 'removed') && (
                                                <Button
                                                    size="lg"
                                                    onClick={() =>
                                                        install(i.key)
                                                    }
                                                >
                                                    Install
                                                </Button>
                                            )}
                                            {i.status === 'update' && (
                                                <Button
                                                    size="lg"
                                                    onClick={() =>
                                                        install(i.key)
                                                    }
                                                >
                                                    Update to the new version
                                                </Button>
                                            )}
                                            {i.status === 'changed' && (
                                                <Button
                                                    size="lg"
                                                    variant="outline"
                                                    onClick={() =>
                                                        install(i.key, true)
                                                    }
                                                >
                                                    Install again as a new copy
                                                </Button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                        </ul>
                    </section>
                ))}
            </div>
        </>
    );
}

SetupLibrary.layout = {
    breadcrumbs: [{ title: 'Ready-made library', href: '/setup/library' }],
};
