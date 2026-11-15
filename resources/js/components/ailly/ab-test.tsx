import { router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

export type AbTestSettings = {
    on: boolean;
    greeting_b: string;
    gate_b: 'before' | 'after' | 'booking' | null;
    started_at: string | null;
};
type Side = { chats: number; leads: number; booked: number; rate: number };
export type AbTestResults = {
    a: Side;
    b: Side;
    verdict: string;
    winner: 'a' | 'b' | null;
} | null;

const gates = {
    before: 'Before the first answer',
    after: 'After a few answers',
    booking: 'Only when booking',
};

/**
 * A/B test of AIlly's greeting and when it asks for details: half of new chats see version B; the results say in
 * plain words when one version really is better.
 */
export function AbTest({
    test,
    results,
    current,
}: {
    test: AbTestSettings;
    results: AbTestResults;
    current: { greeting: string; gate: 'before' | 'after' | 'booking' };
}) {
    const form = useForm({
        greeting_b: test.greeting_b,
        gate_b: test.gate_b ?? '',
    });
    const stop = (useB: boolean) =>
        router.post(
            '/settings/ailly/test/stop',
            { use_b: useB },
            { preserveScroll: true },
        );

    return (
        <div className="grid gap-4 text-sm">
            {results && (
                <section className="grid gap-3 rounded-lg border p-4">
                    <h2 className="flex items-center gap-2 font-medium">
                        Results since{' '}
                        {new Date(test.started_at ?? '').toLocaleDateString()}
                        {test.on ? (
                            <Badge>Running</Badge>
                        ) : (
                            <Badge variant="outline">Ended</Badge>
                        )}
                    </h2>
                    <table className="w-full text-left">
                        <thead className="text-muted-foreground">
                            <tr>
                                <th className="py-1 font-normal">Version</th>
                                <th className="py-1 font-normal">Chats</th>
                                <th className="py-1 font-normal">Leads</th>
                                <th className="py-1 font-normal">Lead rate</th>
                                <th className="py-1 font-normal">Booked</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(['a', 'b'] as const).map((v) => (
                                <tr key={v} className="border-t">
                                    <td className="py-1">
                                        {v === 'a' ? 'A (current)' : 'B (new)'}
                                        {results.winner === v && (
                                            <Badge className="ml-2">
                                                Winner
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="py-1">{results[v].chats}</td>
                                    <td className="py-1">{results[v].leads}</td>
                                    <td className="py-1">{results[v].rate}%</td>
                                    <td className="py-1">
                                        {results[v].booked}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p>{results.verdict}</p>
                    {test.on && (
                        <div className="flex flex-wrap gap-2">
                            <Button onClick={() => stop(true)}>
                                Use B for everyone
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => stop(false)}
                            >
                                End the test, keep A
                            </Button>
                        </div>
                    )}
                </section>
            )}

            {!test.on && (
                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/settings/ailly/test', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <h2 className="font-medium">Test a new version</h2>
                    <p className="text-muted-foreground">
                        Half of new chats see version B (a returning visitor
                        always sees the same one). Version A is what visitors
                        see now: “{current.greeting}” · asks for details{' '}
                        {gates[current.gate].toLowerCase()}.
                    </p>
                    <label className="grid gap-1">
                        Greeting for version B (leave empty to keep A's)
                        <Textarea
                            rows={2}
                            maxLength={240}
                            value={form.data.greeting_b}
                            onChange={(e) =>
                                form.setData('greeting_b', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        When version B asks for name, email and phone
                        <select
                            className="h-9 rounded-md border bg-background px-2"
                            value={form.data.gate_b}
                            onChange={(e) =>
                                form.setData(
                                    'gate_b',
                                    e.target.value as typeof form.data.gate_b,
                                )
                            }
                        >
                            <option value="">Same as A</option>
                            {Object.entries(gates).map(([k, label]) => (
                                <option key={k} value={k}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    {form.errors.greeting_b && (
                        <p className="text-destructive">
                            {form.errors.greeting_b}
                        </p>
                    )}
                    <div>
                        <Button disabled={form.processing}>
                            Start the test
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
}
