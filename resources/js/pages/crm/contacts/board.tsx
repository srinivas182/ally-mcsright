import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';

type Card = {
    ulid: string;
    name: string;
    phone: string | null;
    origin: string;
    days_in_stage: number | null;
};
type Column = {
    ulid: string;
    name: string;
    outcome: 'won' | 'lost' | null;
    count: number;
    contacts: Card[];
};
type Props = {
    pipelines: { ulid: string; name: string }[];
    pipeline: { ulid: string; name: string };
    stages: Column[];
    canManage: boolean;
};

/** Pipeline board: drag a card to another stage, or use its menu (phones, keyboards). */
export default function Board({ pipelines, pipeline, stages }: Props) {
    const [dragging, setDragging] = useState<string | null>(null);
    const [over, setOver] = useState<string | null>(null);
    const move = (contact: string, stage: string) =>
        router.post(
            `/contacts/${contact}/stage`,
            { stage },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <>
            <Head title={`${pipeline.name} pipeline`} />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Pipeline"
                    description="Drag contacts between stages. Every move is noted on their timeline."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/contacts">List view</Link>
                        </Button>
                    }
                />
                <Tabs
                    value={pipeline.ulid}
                    onValueChange={(ulid) =>
                        router.get('/contacts/board', { pipeline: ulid })
                    }
                >
                    <TabsList>
                        {pipelines.map((p) => (
                            <TabsTrigger key={p.ulid} value={p.ulid}>
                                {p.name}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>
                <div className="flex gap-3 overflow-x-auto pb-4">
                    {stages.map((stage) => (
                        <section
                            key={stage.ulid}
                            aria-label={`${stage.name}, ${stage.count} contacts`}
                            onDragOver={(e) => {
                                e.preventDefault();
                                setOver(stage.ulid);
                            }}
                            onDragLeave={() => setOver(null)}
                            onDrop={(e) => {
                                e.preventDefault();
                                setOver(null);
                                if (dragging) {
                                    move(dragging, stage.ulid);
                                }
                                setDragging(null);
                            }}
                            className={cn(
                                'flex w-64 shrink-0 flex-col gap-2 rounded-lg border bg-muted/30 p-2',
                                over === stage.ulid &&
                                    'border-primary bg-accent',
                            )}
                        >
                            <h2 className="flex items-center justify-between px-1 text-sm font-semibold">
                                {stage.name}
                                <Badge
                                    variant={
                                        stage.outcome === 'won'
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {stage.count}
                                </Badge>
                            </h2>
                            {stage.contacts.map((card) => (
                                <article
                                    key={card.ulid}
                                    draggable
                                    onDragStart={() => setDragging(card.ulid)}
                                    className="grid gap-1 rounded-md border bg-background p-2 text-sm shadow-xs"
                                >
                                    <Link
                                        href={`/contacts/${card.ulid}`}
                                        className="font-medium hover:underline"
                                    >
                                        {card.name}
                                    </Link>
                                    <span className="text-xs text-muted-foreground">
                                        {card.days_in_stage !== null
                                            ? `${card.days_in_stage} days in stage`
                                            : 'New'}
                                        {card.origin === 'personal'
                                            ? ' · Personal'
                                            : ''}
                                    </span>
                                    <label
                                        className="sr-only"
                                        htmlFor={`move-${card.ulid}`}
                                    >
                                        Move {card.name} to
                                    </label>
                                    <select
                                        id={`move-${card.ulid}`}
                                        className="rounded border bg-background px-1 py-0.5 text-xs"
                                        value={stage.ulid}
                                        onChange={(e) =>
                                            move(card.ulid, e.target.value)
                                        }
                                    >
                                        {stages.map((s) => (
                                            <option key={s.ulid} value={s.ulid}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </select>
                                </article>
                            ))}
                            {stage.count > stage.contacts.length && (
                                <p className="px-1 text-xs text-muted-foreground">
                                    +{stage.count - stage.contacts.length} more
                                </p>
                            )}
                        </section>
                    ))}
                </div>
            </div>
        </>
    );
}

Board.layout = {
    breadcrumbs: [
        { title: 'Contacts', href: '/contacts' },
        { title: 'Pipeline', href: '/contacts/board' },
    ],
};
