import { Head, router, useForm } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type CellRow = {
    id: number;
    ulid: string;
    name: string;
    region: string;
    status: string;
    dedicated: boolean;
    auto_full: boolean;
    tenants: number;
    capacity_tenants: number;
    agents: number;
    capacity_agents: number;
    percent: number;
    checked_at: string | null;
    last_check: { label: string; ok: boolean; detail: string }[] | null;
};

const STATUS: Record<string, string> = {
    active: 'Open to new brokerages',
    full: 'Full: no new brokerages',
    draining: 'Draining',
    maintenance: 'Not open yet',
};

/** PS1 (specs/core/14): HQ › Cells: how full each cell is, adding a cell, opening and closing them. */
export default function Cells({
    cells,
    warn,
    reopen,
}: {
    cells: CellRow[];
    warn: number;
    reopen: number;
}) {
    const form = useForm({
        name: '',
        region: 'us-east',
        driver: 'mysql',
        host: '',
        port: '3306',
        database: '',
        username: '',
        password: '',
        capacity_tenants: '2000',
        capacity_agents: '50000',
    });

    return (
        <>
            <Head title="Cells" />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Cells"
                    description={`Each cell is a database holding many brokerages. At ${warn}% full we email you; at 100% a cell closes itself to new brokerages and reopens below ${reopen}%.`}
                />
                <ul className="grid gap-3">
                    {cells.map((c) => (
                        <li
                            key={c.id}
                            className="grid gap-2 rounded-lg border p-4 text-base"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <strong className="text-lg">
                                    {c.name}{' '}
                                    <span className="text-sm font-normal text-muted-foreground">
                                        {c.region}
                                    </span>
                                </strong>
                                <span className="flex flex-wrap gap-2">
                                    {c.dedicated && (
                                        <Badge variant="secondary">
                                            Dedicated
                                        </Badge>
                                    )}
                                    <Badge
                                        variant={
                                            c.status === 'active'
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {STATUS[c.status] ?? c.status}
                                        {c.auto_full ? ' (automatic)' : ''}
                                    </Badge>
                                </span>
                            </div>
                            <div
                                className="h-3 w-full overflow-hidden rounded-full bg-muted"
                                role="meter"
                                aria-label={`${c.name} is ${c.percent}% full`}
                                aria-valuenow={c.percent}
                                aria-valuemin={0}
                                aria-valuemax={100}
                            >
                                <div
                                    className={
                                        c.percent >= warn
                                            ? 'h-full bg-destructive'
                                            : 'h-full bg-primary'
                                    }
                                    style={{
                                        width: `${Math.min(100, c.percent)}%`,
                                    }}
                                />
                            </div>
                            <p>
                                {c.percent}% full · {c.tenants} of{' '}
                                {c.capacity_tenants} brokerages · {c.agents} of{' '}
                                {c.capacity_agents} agents
                            </p>
                            {c.last_check && (
                                <ul className="text-sm">
                                    {c.last_check.map((k) => (
                                        <li key={k.label}>
                                            {k.ok ? '✓' : '✗'} {k.label}:{' '}
                                            {k.detail}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {!c.dedicated && (
                                <div className="flex flex-wrap gap-2">
                                    {c.status !== 'active' && (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/hq/cells/${c.ulid}/status`,
                                                    { status: 'active' },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Open to new brokerages
                                        </Button>
                                    )}
                                    {c.status === 'active' && (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/hq/cells/${c.ulid}/status`,
                                                    { status: 'full' },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Close to new brokerages
                                        </Button>
                                    )}
                                    {c.last_check && (
                                        <Button
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    `/hq/cells/${c.ulid}/prepare`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Check again
                                        </Button>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
                <form
                    className="grid gap-3 rounded-lg border p-4 text-base"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/hq/cells', { preserveScroll: true });
                    }}
                >
                    <h2 className="text-lg font-semibold">Add a cell</h2>
                    <p className="text-sm text-muted-foreground">
                        Create the empty database first. We connect, copy the
                        tables from the main cell, check them, and only then
                        open it. Connection details are stored encrypted and
                        never shown again.
                    </p>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {(
                            [
                                ['name', 'Name (e.g. us-east-2)'],
                                ['region', 'Region'],
                                ['host', 'Host'],
                                ['port', 'Port'],
                                ['database', 'Database name'],
                                ['username', 'User'],
                            ] as const
                        ).map(([k, label]) => (
                            <label key={k} className="grid gap-1">
                                {label}
                                <Input
                                    value={form.data[k]}
                                    onChange={(e) =>
                                        form.setData(k, e.target.value)
                                    }
                                />
                            </label>
                        ))}
                        <label className="grid gap-1">
                            Password
                            <Input
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password}
                                onChange={(e) =>
                                    form.setData('password', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            Brokerages it can hold
                            <Input
                                inputMode="numeric"
                                value={form.data.capacity_tenants}
                                onChange={(e) =>
                                    form.setData(
                                        'capacity_tenants',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            Agents it can hold
                            <Input
                                inputMode="numeric"
                                value={form.data.capacity_agents}
                                onChange={(e) =>
                                    form.setData(
                                        'capacity_agents',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                    </div>
                    <InputError
                        message={form.errors.database ?? form.errors.name}
                    />
                    <div>
                        <Button
                            type="submit"
                            size="lg"
                            disabled={form.processing}
                        >
                            Add and check the cell
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
