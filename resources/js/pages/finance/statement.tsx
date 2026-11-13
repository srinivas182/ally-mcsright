import { Head, router } from '@inertiajs/react';

import { PageHeader } from '@/components/ui/page-header';
import { Input } from '@/components/ui/input';
import { usd } from '@/lib/cents';

type Props = {
    statement: {
        month: string;
        opening: { owed_to_you: number; you_owe: number };
        closing: { owed_to_you: number; you_owe: number };
        lines: { date: string; text: string; cents: number }[];
        earned: number;
        paid: number;
    };
};

/** Monthly statement: every cent explained. */
export default function Statement({ statement: s }: Props) {
    return (
        <>
            <Head title="Statement" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Statement"
                    description="Every cent you earned, owed and were paid."
                    actions={
                        <a
                            className="text-sm underline"
                            href={`/my-money/statement?month=${s.month}&format=pdf`}
                        >
                            Download PDF
                        </a>
                    }
                />
                <Input
                    aria-label="Month"
                    type="month"
                    className="w-44"
                    value={s.month}
                    onChange={(e) =>
                        router.get('/my-money/statement', {
                            month: e.target.value,
                        })
                    }
                />
                <p className="text-sm">
                    Start of month: owed to you {usd(s.opening.owed_to_you)} ·
                    you owe {usd(s.opening.you_owe)}
                </p>
                <ul className="divide-y rounded-lg border text-sm">
                    {s.lines.length === 0 && (
                        <li className="p-3 text-muted-foreground">
                            Nothing this month.
                        </li>
                    )}
                    {s.lines.map((l, i) => (
                        <li key={i} className="flex justify-between gap-2 p-3">
                            <span>
                                <span className="text-muted-foreground">
                                    {new Date(l.date).toLocaleDateString()}
                                </span>{' '}
                                · {l.text}
                            </span>
                            <span
                                className={
                                    l.cents < 0
                                        ? 'text-muted-foreground'
                                        : 'font-medium'
                                }
                            >
                                {l.cents === 0
                                    ? ''
                                    : `${l.cents < 0 ? '−' : ''}${usd(Math.abs(l.cents))}`}
                            </span>
                        </li>
                    ))}
                </ul>
                <p className="text-sm">
                    End of month: owed to you {usd(s.closing.owed_to_you)} · you
                    owe {usd(s.closing.you_owe)} · earned {usd(s.earned)} · paid
                    to you {usd(s.paid)}
                </p>
            </div>
        </>
    );
}

Statement.layout = {
    breadcrumbs: [
        { title: 'My money', href: '/my-money' },
        { title: 'Statement', href: '/my-money/statement' },
    ],
};
