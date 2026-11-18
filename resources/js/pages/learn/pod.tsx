import { Head, router, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Member = {
    name: string;
    me: boolean;
    goal: string | null;
    done: string | null;
    help: string | null;
    calls: number;
    appointments: number;
    deals: number;
};

type Pod = { ulid: string; name: string; week: string; members: Member[] };

/** LN4 (E20): one pod's week: everyone's goal and check-in next to their numbers. */
export default function PodPage({ pod }: { pod: Pod }) {
    const me = pod.members.find((m) => m.me);
    const form = useForm({
        goal: me?.goal ?? '',
        done: me?.done ?? '',
        help: me?.help ?? '',
    });

    return (
        <>
            <Head title={pod.name} />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title={pod.name}
                    description={`Week of ${new Date(pod.week + 'T12:00:00').toLocaleDateString()}`}
                />
                {me && (
                    <form
                        className="grid gap-3 rounded-lg border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/learn/pods/${pod.ulid}/check-in`, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <h2 className="text-lg font-semibold">My check-in</h2>
                        <label className="grid gap-1">
                            This week&rsquo;s goal
                            <Input
                                value={form.data.goal}
                                maxLength={300}
                                onChange={(e) =>
                                    form.setData('goal', e.target.value)
                                }
                                placeholder="Call 20 past clients and book 2 appointments"
                            />
                        </label>
                        <label className="grid gap-1">
                            What I did (at the end of the week)
                            <Input
                                value={form.data.done}
                                maxLength={300}
                                onChange={(e) =>
                                    form.setData('done', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            Where I need help
                            <Input
                                value={form.data.help}
                                maxLength={300}
                                onChange={(e) =>
                                    form.setData('help', e.target.value)
                                }
                            />
                        </label>
                        <Button
                            className="justify-self-start"
                            disabled={form.processing}
                        >
                            Save my check-in
                        </Button>
                    </form>
                )}
                <ul className="grid gap-3">
                    {pod.members.map((m) => (
                        <li
                            key={m.name}
                            className="grid gap-1 rounded-lg border p-4"
                        >
                            <p className="font-medium">{m.name}</p>
                            <p className="text-sm text-muted-foreground">
                                This week: {m.calls} calls logged ·{' '}
                                {m.appointments} appointments · {m.deals} new
                                deals
                            </p>
                            <p>
                                <strong>Goal:</strong> {m.goal ?? 'Not set yet'}
                            </p>
                            {m.done && (
                                <p>
                                    <strong>Did:</strong> {m.done}
                                </p>
                            )}
                            {m.help && (
                                <p>
                                    <strong>Needs help with:</strong> {m.help}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
                {me && (
                    <Button
                        variant="outline"
                        className="justify-self-start"
                        onClick={() =>
                            router.post(`/learn/pods/${pod.ulid}/leave`)
                        }
                    >
                        Leave this pod
                    </Button>
                )}
            </div>
        </>
    );
}
