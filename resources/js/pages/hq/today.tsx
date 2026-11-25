import { Head, Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Item = {
    key: string;
    area: string;
    label: string;
    do: string;
    count: number;
    href: string;
};

type Props = { items: Item[]; at: string };

/**
 * HQ3: Today › To do. Everything waiting for someone in HQ, in one list, most urgent first; each line says what
 * to do and opens the page where it is done. People only see the lines for the parts of HQ their role allows.
 */
export default function HqToday({ items, at }: Props) {
    const total = items.reduce((sum, item) => sum + item.count, 0);

    return (
        <>
            <Head title="To do" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="To do"
                    description={
                        total > 0
                            ? `${total} ${total === 1 ? 'thing is' : 'things are'} waiting for HQ. Checked ${new Date(at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}.`
                            : 'Everything waiting for HQ shows here.'
                    }
                />

                {items.length === 0 ? (
                    <div className="grid justify-items-center gap-3 rounded-xl border border-dashed p-10 text-center">
                        <CheckCircle2
                            className="size-10 text-emerald-600"
                            aria-hidden
                        />
                        <p className="text-lg font-semibold">Nothing waiting</p>
                        <p className="max-w-md text-muted-foreground">
                            New support requests, sign-ups that got stuck, MLS
                            access requests and unpaid bills will show up here
                            as soon as they come in.
                        </p>
                    </div>
                ) : (
                    <ul className="grid gap-3" aria-label="Waiting for HQ">
                        {items.map((item) => (
                            <li
                                key={item.key}
                                className="flex flex-wrap items-center gap-4 rounded-xl border bg-card p-4"
                            >
                                <span
                                    className="grid size-12 shrink-0 place-items-center rounded-full bg-amber-100 text-xl font-bold text-amber-900 dark:bg-amber-900/40 dark:text-amber-100"
                                    aria-label={`${item.count} waiting`}
                                >
                                    {item.count}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-base font-semibold">
                                        {item.label}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {item.do}
                                    </p>
                                </div>
                                <Button asChild size="lg">
                                    <Link href={item.href}>Open</Link>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
