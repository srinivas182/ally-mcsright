import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2Icon, CircleIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';

type Step = {
    key: string;
    title: string;
    why: string;
    done: boolean;
    href: string;
    action: string;
};

/**
 * MK2: "Get set up in about 10 minutes" — the owner's first page after signing up. Five big steps, ticked off from
 * what is really done; each has one button.
 */
export default function Setup({
    firstName,
    steps,
    done,
    total,
}: {
    firstName: string;
    steps: Step[];
    done: number;
    total: number;
}) {
    return (
        <>
            <Head title="Get set up" />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-8">
                <header className="grid gap-2">
                    <h1 className="text-3xl font-semibold">
                        Welcome, {firstName}. Let's get you set up.
                    </h1>
                    <p className="text-lg text-muted-foreground">
                        About 10 minutes. {done} of {total} done. You can stop
                        any time and come back here from Help.
                    </p>
                    <div
                        className="h-3 w-full overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={total}
                        aria-valuenow={done}
                        aria-label="Set-up progress"
                    >
                        <div
                            className="h-full bg-primary"
                            style={{ width: `${(done / total) * 100}%` }}
                        />
                    </div>
                </header>
                <ol className="grid gap-3">
                    {steps.map((s, i) => (
                        <li
                            key={s.key}
                            className={`grid gap-3 rounded-2xl border-2 p-5 ${s.done ? 'border-green-600/40' : ''}`}
                        >
                            <div className="flex items-start gap-3">
                                {s.done ? (
                                    <CheckCircle2Icon
                                        className="mt-1 size-7 shrink-0 text-green-700 dark:text-green-400"
                                        aria-label="Done"
                                    />
                                ) : (
                                    <CircleIcon
                                        className="mt-1 size-7 shrink-0 text-muted-foreground"
                                        aria-label="Not done yet"
                                    />
                                )}
                                <div className="grid gap-1">
                                    <h2 className="text-xl font-semibold">
                                        {i + 1}. {s.title}
                                    </h2>
                                    <p className="text-lg text-muted-foreground">
                                        {s.why}
                                    </p>
                                </div>
                            </div>
                            {!s.done &&
                                (s.key === 'website' ? (
                                    <Button
                                        size="lg"
                                        className="h-14 text-lg"
                                        onClick={() =>
                                            router.post('/setup/website')
                                        }
                                    >
                                        {s.action}
                                    </Button>
                                ) : (
                                    <Button
                                        size="lg"
                                        className="h-14 text-lg"
                                        asChild
                                    >
                                        <Link href={s.href}>{s.action}</Link>
                                    </Button>
                                ))}
                        </li>
                    ))}
                </ol>
                <p className="text-lg">
                    Rather we did it?{' '}
                    <Link href="/help" className="underline">
                        Send us your spreadsheets
                    </Link>{' '}
                    and we will set up your people and agents for you.
                </p>
                <Button variant="outline" size="lg" className="h-14" asChild>
                    <Link href="/dashboard">Go to my home page</Link>
                </Button>
            </div>
        </>
    );
}
