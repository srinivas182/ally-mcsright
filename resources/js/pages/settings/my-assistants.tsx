import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Assistant = {
    ulid: string;
    name: string;
    email: string;
    status: string;
    preset: string;
};

type Props = {
    assistants: Assistant[];
    waiting: { email: string; name: string }[];
    presets: Record<string, string>;
    max: number;
};

/**
 * AG1 (specs/grow/03): an agent adds their own assistants. Assistants work only the agent's own contacts and deals,
 * and open only what the chosen preset allows; never the agent's money.
 */
export default function MyAssistants({
    assistants,
    waiting,
    presets,
    max,
}: Props) {
    const form = useForm({
        email: '',
        first_name: '',
        last_name: '',
        preset: 'full',
    });
    const opts = { preserveScroll: true };
    const full = assistants.length >= max;

    return (
        <>
            <Head title="My assistants" />
            <div className="mx-auto grid w-full max-w-3xl gap-8 p-4 text-base md:p-6">
                <PageHeader
                    title="My assistants"
                    description="Add someone who helps you. They see only your own contacts and deals, and only the parts you choose. They never see your pay."
                />

                {assistants.length > 0 && (
                    <ul className="grid gap-3">
                        {assistants.map((a) => (
                            <li
                                key={a.ulid}
                                className="grid gap-2 rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="grow font-medium">
                                        {a.name}{' '}
                                        <span className="text-sm font-normal text-muted-foreground">
                                            {a.email}
                                        </span>
                                    </span>
                                    {a.status === 'invited' && (
                                        <Badge variant="outline">Invited</Badge>
                                    )}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <select
                                        aria-label={`What ${a.name} may do`}
                                        className="h-10 rounded-md border bg-background px-3"
                                        value={a.preset}
                                        onChange={(e) =>
                                            router.post(
                                                `/my-assistants/${a.ulid}`,
                                                { preset: e.target.value },
                                                opts,
                                            )
                                        }
                                    >
                                        {Object.entries(presets).map(
                                            ([k, v]) => (
                                                <option key={k} value={k}>
                                                    {v}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/my-assistants/${a.ulid}/remove`,
                                                {},
                                                opts,
                                            )
                                        }
                                    >
                                        Remove
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {waiting.length > 0 && (
                    <p className="text-muted-foreground">
                        Waiting to accept:{' '}
                        {waiting
                            .map((w) => `${w.name} (${w.email})`)
                            .join(', ')}
                    </p>
                )}

                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/my-assistants', {
                            ...opts,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <h2 className="text-lg font-semibold">Add an assistant</h2>
                    {full && (
                        <p className="text-muted-foreground">
                            You have {max} assistants, the most you can have.
                            Remove one to add another.
                        </p>
                    )}
                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="grid gap-1">
                            First name
                            <Input
                                value={form.data.first_name}
                                onChange={(e) =>
                                    form.setData('first_name', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            Last name
                            <Input
                                value={form.data.last_name}
                                onChange={(e) =>
                                    form.setData('last_name', e.target.value)
                                }
                            />
                        </label>
                    </div>
                    <label className="grid gap-1">
                        Email
                        <Input
                            type="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                    </label>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1">What may they do?</legend>
                        {Object.entries(presets).map(([k, v]) => (
                            <label key={k} className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    className="size-5"
                                    name="preset"
                                    checked={form.data.preset === k}
                                    onChange={() => form.setData('preset', k)}
                                />
                                {v}
                            </label>
                        ))}
                    </fieldset>
                    <Button
                        className="justify-self-start"
                        disabled={form.processing || full}
                    >
                        Send the invitation
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
