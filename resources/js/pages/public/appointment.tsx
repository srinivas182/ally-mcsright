import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    valid: boolean;
    done: string;
    appointment: {
        title: string;
        street: string;
        city: string | null;
        starts_at: string;
        status: string;
    } | null;
};

/** A vendor confirms an appointment or suggests another time (specs/ai/02, DQ5b). No account; never indexed. */
export default function Appointment({ valid, done, appointment: a }: Props) {
    const [other, setOther] = useState(false);
    const form = useForm({ confirm: true, proposed: '', note: '' });

    return (
        <>
            <Head title="Your appointment">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main className="mx-auto grid max-w-lg gap-4 p-6 text-sm">
                <h1 className="text-xl font-semibold">Your appointment</h1>
                {done !== '' ? (
                    <p role="status">
                        {done === 'confirmed'
                            ? 'Thank you, it is confirmed. The agent has been told.'
                            : 'Thank you. The agent will confirm the new time with you.'}
                    </p>
                ) : !valid || !a ? (
                    <p>
                        This appointment can no longer be changed here. Please
                        contact the agent.
                    </p>
                ) : (
                    <>
                        <p>
                            <strong>{a.title}</strong> at {a.street}
                            {a.city ? `, ${a.city}` : ''}
                            <br />
                            {a.starts_at}
                        </p>
                        <Input
                            aria-label="Note for the agent (optional)"
                            placeholder="Note for the agent (optional)"
                            value={form.data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                        {other ? (
                            <form
                                className="grid gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.transform((d) => ({
                                        ...d,
                                        confirm: false,
                                    }));
                                    form.post(window.location.pathname);
                                }}
                            >
                                <label className="grid gap-1">
                                    <span>A time that works for you</span>
                                    <Input
                                        type="datetime-local"
                                        required
                                        value={form.data.proposed}
                                        onChange={(e) =>
                                            form.setData(
                                                'proposed',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                {form.errors.proposed && (
                                    <span className="text-destructive">
                                        {form.errors.proposed}
                                    </span>
                                )}
                                <Button
                                    type="submit"
                                    className="justify-self-start"
                                    disabled={form.processing}
                                >
                                    Suggest this time
                                </Button>
                            </form>
                        ) : (
                            <span className="flex flex-wrap gap-2">
                                <Button
                                    disabled={form.processing}
                                    onClick={() => {
                                        form.transform((d) => ({
                                            ...d,
                                            confirm: true,
                                        }));
                                        form.post(window.location.pathname);
                                    }}
                                >
                                    Confirm
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setOther(true)}
                                >
                                    Suggest another time
                                </Button>
                            </span>
                        )}
                    </>
                )}
            </main>
        </>
    );
}
