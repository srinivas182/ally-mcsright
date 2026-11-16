import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Check = { check: string; ok: boolean; detail: string };

type StateRow = {
    code: string;
    name: string;
    on: boolean;
    turned_on_at: string | null;
    checks: Check[];
    ready: boolean;
};

type Props = { states: StateRow[]; order: string[] };

export default function HqMovewiseLaunch({ states, order }: Props) {
    const today = new Date().toISOString().slice(0, 10);
    const [from, setFrom] = useState(
        new Date(Date.now() - 90 * 864e5).toISOString().slice(0, 10),
    );
    const [to, setTo] = useState(today);
    const q = `?from=${from}&to=${to}`;

    return (
        <>
            <Head title="Movewise launch" />
            <div className="grid gap-6 p-4">
                <PageHeader
                    title="Movewise launch"
                    description={`Launch order: ${order.join(', then ')}. Each state goes live only when every check is green and the legal checklist is signed off.`}
                />
                {states.map((s) => (
                    <section
                        key={s.code}
                        className="grid gap-2 rounded-xl border p-3 text-sm"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <strong>{s.name}</strong>
                            <Badge variant={s.on ? 'default' : 'outline'}>
                                {s.on ? `Live since ${s.turned_on_at}` : 'Off'}
                            </Badge>
                            <Badge
                                variant={s.ready ? 'default' : 'destructive'}
                            >
                                {s.ready ? 'All checks green' : 'Not ready'}
                            </Badge>
                        </div>
                        <ul className="grid gap-1">
                            {s.checks.map((c) => (
                                <li key={c.check}>
                                    <span aria-hidden>{c.ok ? '✓' : '✗'}</span>{' '}
                                    <span
                                        className={
                                            c.ok
                                                ? ''
                                                : 'font-medium text-destructive'
                                        }
                                    >
                                        {c.check}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {c.detail}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
                <section className="grid gap-2 text-sm">
                    <h2 className="text-lg font-semibold">Audit packs</h2>
                    <p className="text-muted-foreground">
                        CSV files for counsel and regulators. Phone numbers are
                        never included. Each download is logged.
                    </p>
                    <div className="flex flex-wrap items-end gap-2">
                        <label className="grid gap-1">
                            From
                            <Input
                                type="date"
                                value={from}
                                max={to}
                                onChange={(e) => setFrom(e.target.value)}
                            />
                        </label>
                        <label className="grid gap-1">
                            To
                            <Input
                                type="date"
                                value={to}
                                max={today}
                                onChange={(e) => setTo(e.target.value)}
                            />
                        </label>
                        <a
                            className="underline"
                            href={`/hq/movewise/audit/fair-housing.csv${q}`}
                        >
                            Fair Housing pack
                        </a>
                        <a
                            className="underline"
                            href={`/hq/movewise/audit/consent.csv${q}`}
                        >
                            Consent pack
                        </a>
                    </div>
                </section>
            </div>
        </>
    );
}
