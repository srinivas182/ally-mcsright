import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Monogram, type AppEntry } from '@/components/connect/monogram';
import { PageHeader } from '@/components/ui/page-header';

/** Apps: connect the tools you already use (specs/connect/01). */
export default function Apps({
    categories,
    apps,
}: {
    categories: Record<string, string>;
    apps: AppEntry[];
}) {
    const [q, setQ] = useState('');
    const [show, setShow] = useState<'all' | 'connected' | 'available'>('all');
    const list = useMemo(
        () =>
            apps.filter(
                (a) =>
                    (q === '' ||
                        `${a.name} ${a.summary} ${a.category_name}`
                            .toLowerCase()
                            .includes(q.toLowerCase())) &&
                    (show === 'all' ||
                        (show === 'connected' && a.connected > 0) ||
                        (show === 'available' && a.status === 'available')),
            ),
        [apps, q, show],
    );

    return (
        <>
            <Head title="Apps" />
            <div className="grid gap-6 p-4">
                <PageHeader
                    title="Apps"
                    description="Connect the tools you already use and bring their data into RightAlly. Connecting is free; you only pay a vendor for what you buy from them."
                />
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        className="max-w-xs"
                        placeholder="Search apps"
                        aria-label="Search apps"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                    />
                    {(['all', 'connected', 'available'] as const).map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => setShow(s)}
                            className={`h-9 rounded-md border px-3 text-sm ${show === s ? 'bg-primary text-primary-foreground' : 'bg-background'}`}
                        >
                            {s === 'all'
                                ? 'All'
                                : s === 'connected'
                                  ? 'Connected'
                                  : 'Ready to connect'}
                        </button>
                    ))}
                </div>

                {Object.entries(categories).map(([key, title]) => {
                    const inCategory = list.filter((a) => a.category === key);
                    if (inCategory.length === 0) return null;

                    return (
                        <section key={key} className="grid gap-3">
                            <h2 className="text-sm font-semibold">{title}</h2>
                            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {inCategory.map((a) => (
                                    <li key={a.key}>
                                        <Link
                                            href={`/apps/${a.key}`}
                                            className="flex h-full gap-3 rounded-lg border p-4 text-sm hover:bg-muted/40"
                                        >
                                            <Monogram name={a.name} />
                                            <span className="grid gap-1">
                                                <span className="flex flex-wrap items-center gap-2 font-medium">
                                                    {a.name}
                                                    {a.connected > 0 &&
                                                        !a.problem && (
                                                            <Badge>
                                                                Connected
                                                            </Badge>
                                                        )}
                                                    {a.problem && (
                                                        <Badge variant="destructive">
                                                            Needs attention
                                                        </Badge>
                                                    )}
                                                    {a.status === 'soon' && (
                                                        <Badge variant="outline">
                                                            Coming soon
                                                        </Badge>
                                                    )}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {a.summary}
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    );
                })}
                {list.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No apps match. Ask us for the one you need from any
                        app's page, or use webhooks and Zapier in the meantime.
                    </p>
                )}
            </div>
        </>
    );
}

Apps.layout = {
    breadcrumbs: [{ title: 'Apps', href: '/apps' }],
};
