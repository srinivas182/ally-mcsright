import { Head, Link, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

/** LN4 (E20): accountability pods: a few agents who keep each other on track. */
export default function Pods({
    pods,
    people,
    min,
    max,
}: {
    pods: { ulid: string; name: string; members: number }[];
    people: { ulid: string; name: string }[];
    min: number;
    max: number;
}) {
    const form = useForm<{ name: string; members: string[] }>({
        name: '',
        members: [],
    });

    return (
        <>
            <Head title="Accountability pods" />
            <div className="mx-auto grid w-full max-w-2xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Accountability pods"
                    description="A few agents who set one goal each week and check in on each other. Your calls, appointments and new deals show up by themselves."
                />
                {pods.length > 0 && (
                    <ul className="divide-y rounded-lg border">
                        {pods.map((p) => (
                            <li key={p.ulid} className="p-3">
                                <Link
                                    href={`/learn/pods/${p.ulid}`}
                                    className="font-medium underline"
                                >
                                    {p.name}
                                </Link>{' '}
                                <span className="text-muted-foreground">
                                    · {p.members} people
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/learn/pods');
                    }}
                >
                    <h2 className="text-lg font-semibold">Start a pod</h2>
                    <label className="grid gap-1">
                        Name
                        <Input
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="Tuesday growth pod"
                        />
                    </label>
                    <fieldset className="grid gap-1">
                        <legend className="mb-1">
                            Who joins ({min - 1} to {max - 1} people besides
                            you)
                        </legend>
                        <div className="grid max-h-64 gap-1 overflow-auto rounded-md border p-2">
                            {people.map((p) => (
                                <label
                                    key={p.ulid}
                                    className="flex items-center gap-2"
                                >
                                    <input
                                        type="checkbox"
                                        className="size-5"
                                        checked={form.data.members.includes(
                                            p.ulid,
                                        )}
                                        onChange={(e) =>
                                            form.setData(
                                                'members',
                                                e.target.checked
                                                    ? [
                                                          ...form.data.members,
                                                          p.ulid,
                                                      ]
                                                    : form.data.members.filter(
                                                          (m) => m !== p.ulid,
                                                      ),
                                            )
                                        }
                                    />
                                    {p.name}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <Button
                        className="justify-self-start"
                        disabled={form.processing}
                    >
                        Start the pod
                    </Button>
                    {Object.values(form.errors)[0] && (
                        <p role="alert" className="text-destructive">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                </form>
            </div>
        </>
    );
}
