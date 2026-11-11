import { Head, Link, router } from '@inertiajs/react';
import { SparklesIcon } from 'lucide-react';
import { useState } from 'react';

import { VoiceButton } from '@/components/crm/voice-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Result = {
    ulid: string;
    name: string;
    lifecycle: string;
    last_activity_at: string | null;
    reason: string;
};
type Props = {
    q: string;
    explanation: string | null;
    filters: Record<string, unknown> | null;
    results: Result[];
    error: string | null;
};

const examples = [
    'Buyers relocating to Fondren I have not called in 2 weeks',
    'Who mentioned retiring or downsizing?',
    'Past clients in Madison',
    'New leads from Zillow this month',
];

export default function Ask({
    q,
    explanation,
    filters,
    results,
    error,
}: Props) {
    const [question, setQuestion] = useState(q);
    const [listName, setListName] = useState(q);
    const ask = (value: string) =>
        value.trim() !== '' && router.get('/contacts/ask', { q: value });

    return (
        <>
            <Head title="Ask your database" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Ask your database"
                    description="Type or speak. Only contacts you can see are searched."
                />
                <form
                    className="flex flex-wrap items-start gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        ask(question);
                    }}
                >
                    <Input
                        aria-label="Your question"
                        className="min-w-64 flex-1"
                        placeholder={examples[0]}
                        value={question}
                        onChange={(e) => setQuestion(e.target.value)}
                    />
                    <VoiceButton
                        label="Ask by voice"
                        onText={(t) => {
                            setQuestion(t);
                        }}
                    />
                    <Button type="submit">
                        <SparklesIcon aria-hidden="true" /> Ask
                    </Button>
                </form>

                {!q && (
                    <div className="flex flex-wrap gap-2">
                        {examples.map((ex) => (
                            <Button
                                key={ex}
                                variant="outline"
                                size="sm"
                                onClick={() => ask(ex)}
                            >
                                {ex}
                            </Button>
                        ))}
                    </div>
                )}

                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                {explanation && (
                    <p className="text-sm text-muted-foreground">
                        Searched for: {explanation}
                    </p>
                )}

                {q && !error && results.length === 0 && (
                    <EmptyState
                        title="No matching contacts"
                        description="Try different words."
                    />
                )}

                {results.length > 0 && (
                    <>
                        <ul className="divide-y rounded-lg border">
                            {results.map((r) => (
                                <li key={r.ulid}>
                                    <Link
                                        href={`/contacts/${r.ulid}`}
                                        className="grid gap-1 p-3 hover:bg-muted/40"
                                    >
                                        <span className="flex items-center gap-2 font-medium">
                                            {r.name}
                                            <Badge
                                                variant="outline"
                                                className="capitalize"
                                            >
                                                {r.lifecycle.replace('_', ' ')}
                                            </Badge>
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {r.reason}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        {filters && (
                            <form
                                className="flex flex-wrap gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.post('/smart-lists', {
                                        name: listName,
                                        filters: JSON.parse(
                                            JSON.stringify(filters),
                                        ),
                                    });
                                }}
                            >
                                <Input
                                    aria-label="List name"
                                    className="max-w-xs"
                                    value={listName}
                                    onChange={(e) =>
                                        setListName(e.target.value)
                                    }
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={listName.trim() === ''}
                                >
                                    Save as a list
                                </Button>
                            </form>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

Ask.layout = {
    breadcrumbs: [
        { title: 'Contacts', href: '/contacts' },
        { title: 'Ask', href: '/contacts/ask' },
    ],
};
