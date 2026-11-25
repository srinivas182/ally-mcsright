import { router, useForm } from '@inertiajs/react';
import { Building2, Check } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type MlsIdData = {
    suggestions: {
        ulid: string;
        mls: string;
        id: string;
        listings: number;
        sample: string[];
    }[];
    boards: { id: number; name: string }[];
    has_id: boolean;
};

/**
 * ML1: on the agent's home. "We found your listings under 4810237 — is this you?" with one-click confirm, or, for
 * agents with no listings yet, one box to type their MLS ID. Once saved, their MLS listings come into RightAlly.
 */
export function MlsIdCard({ data }: { data: MlsIdData }) {
    const form = useForm({ board: data.boards[0]?.id ?? 0, mls_id: '' });
    if (data.suggestions.length === 0 && data.has_id) {
        return null;
    }
    const boardNames = data.boards.map((b) => b.name).join(' and ');

    return (
        <section
            aria-labelledby="mls-id"
            className="grid gap-4 rounded-xl border border-primary/30 bg-primary/5 p-5"
        >
            <div className="flex items-start gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                    <Building2 aria-hidden="true" className="size-5" />
                </span>
                <div className="grid gap-1">
                    <h2 id="mls-id" className="text-lg font-semibold">
                        {data.suggestions.length > 0
                            ? 'Is this your MLS ID?'
                            : 'Bring your MLS listings into RightAlly'}
                    </h2>
                    <p className="text-muted-foreground">
                        {data.suggestions.length > 0
                            ? 'Confirm it once and your listings come into My listings by themselves.'
                            : `Your brokerage is part of ${boardNames}. Enter your MLS ID once and your listings come into My listings by themselves.`}
                    </p>
                </div>
            </div>

            {data.suggestions.map((s) => (
                <div
                    key={s.ulid}
                    className="grid gap-3 rounded-lg border bg-background p-4"
                >
                    <p>
                        We found{' '}
                        <strong>
                            {s.listings}{' '}
                            {s.listings === 1 ? 'listing' : 'listings'}
                        </strong>{' '}
                        in {s.mls} under MLS ID <strong>{s.id}</strong>:
                    </p>
                    {s.sample.length > 0 && (
                        <ul className="grid gap-1 text-sm">
                            {s.sample.map((a) => (
                                <li key={a} className="flex items-center gap-2">
                                    <Check
                                        aria-hidden="true"
                                        className="size-4 text-muted-foreground"
                                    />
                                    {a}
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            onClick={() =>
                                router.post(
                                    `/roster/mls-ids/${s.ulid}/decide`,
                                    { mine: true },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Yes, this is me
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/roster/mls-ids/${s.ulid}/decide`,
                                    { mine: false },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            No, not me
                        </Button>
                    </div>
                </div>
            ))}

            {data.suggestions.length === 0 && (
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/roster/mls-ids/typed', {
                            preserveScroll: true,
                            onSuccess: () => form.reset('mls_id'),
                        });
                    }}
                >
                    {data.boards.length > 1 && (
                        <label className="grid gap-1 text-sm font-medium">
                            MLS board
                            <select
                                className="h-10 rounded-md border bg-background px-3"
                                value={form.data.board}
                                onChange={(e) =>
                                    form.setData(
                                        'board',
                                        Number(e.target.value),
                                    )
                                }
                            >
                                {data.boards.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <label className="grid gap-1 text-sm font-medium">
                        Your MLS ID
                        <Input
                            className="h-10 w-48"
                            autoComplete="off"
                            value={form.data.mls_id}
                            onChange={(e) =>
                                form.setData('mls_id', e.target.value)
                            }
                            required
                        />
                    </label>
                    <Button type="submit" disabled={form.processing}>
                        Find my listings
                    </Button>
                    {(form.errors.mls_id || form.errors.board) && (
                        <p
                            role="alert"
                            className="w-full text-sm text-destructive"
                        >
                            {form.errors.mls_id ?? form.errors.board}
                        </p>
                    )}
                </form>
            )}
        </section>
    );
}
