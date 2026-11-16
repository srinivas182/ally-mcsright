import { Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    automations: {
        ulid: string;
        name: string;
        description: string | null;
        on: boolean;
        version: number;
        summary: string;
        runs: number;
        failed: number;
    }[];
    templates: { key: string; name: string; description: string }[];
    approvals: number;
};

/** Automations (specs/core/06, W1). */
export default function Automations({
    automations,
    templates,
    approvals,
}: Props) {
    const opts = { preserveScroll: true };

    return (
        <>
            <Head title="Automations" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Automations"
                    description="When something happens in RightAlly, do the next steps for you. Automations never move money, delete anything or message clients outside their consent."
                    actions={
                        <span className="flex gap-2">
                            {approvals > 0 && (
                                <Button variant="outline" asChild>
                                    <Link href="/automations/approvals">
                                        Approvals ({approvals})
                                    </Link>
                                </Button>
                            )}
                            <Button asChild>
                                <Link href="/automations/new">
                                    New automation
                                </Link>
                            </Button>
                        </span>
                    }
                />
                {automations.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No automations yet. Start from a ready-made one below,
                        or describe your own in plain English.
                    </p>
                )}
                <ul className="grid gap-3">
                    {automations.map((a) => (
                        <li
                            key={a.ulid}
                            className="grid gap-2 rounded-lg border p-4 text-sm"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <Link
                                    href={`/automations/${a.ulid}`}
                                    className="font-medium underline-offset-2 hover:underline"
                                >
                                    {a.name}
                                </Link>
                                <Badge variant={a.on ? 'default' : 'secondary'}>
                                    {a.on ? 'On' : 'Off'}
                                </Badge>
                                <Badge variant="outline">
                                    Version {a.version}
                                </Badge>
                                {a.failed > 0 && (
                                    <Badge variant="destructive">
                                        {a.failed} failed
                                    </Badge>
                                )}
                                <span className="ml-auto flex gap-2">
                                    <Button size="sm" variant="ghost" asChild>
                                        <Link
                                            href={`/automations/${a.ulid}/runs`}
                                        >
                                            Runs ({a.runs} in 30 days)
                                        </Link>
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant={a.on ? 'outline' : 'default'}
                                        onClick={() =>
                                            router.post(
                                                `/automations/${a.ulid}/switch`,
                                                { on: !a.on },
                                                opts,
                                            )
                                        }
                                    >
                                        {a.on ? 'Switch off' : 'Switch on'}
                                    </Button>
                                </span>
                            </div>
                            <p className="text-muted-foreground">{a.summary}</p>
                        </li>
                    ))}
                </ul>
                {templates.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="text-sm font-medium">
                            Ready-made automations (added switched off, so you
                            can check them first)
                        </h2>
                        <ul className="grid gap-2 md:grid-cols-2">
                            {templates.map((t) => (
                                <li
                                    key={t.key}
                                    className="grid gap-2 rounded-lg border p-3 text-sm"
                                >
                                    <span className="font-medium">
                                        {t.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {t.description}
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="justify-self-start"
                                        onClick={() =>
                                            router.post(
                                                `/automations/templates/${t.key}`,
                                            )
                                        }
                                    >
                                        Add
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

Automations.layout = {
    breadcrumbs: [{ title: 'Automations', href: '/automations' }],
};
