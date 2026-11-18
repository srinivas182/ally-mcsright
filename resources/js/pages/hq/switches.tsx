import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Everyone = {
    key: string;
    label: string;
    about: string;
    default_on: boolean;
    hq_state: 'on' | 'off' | null;
    rollout_percent: number | null;
    note: string | null;
};

type TenantArea = {
    key: string;
    label: string;
    seats_possible: boolean;
    on: boolean;
    hq_state: 'on' | 'off' | null;
    brokerage_off: boolean;
    seats: number | null;
};

type Props = {
    everyone: Everyone[];
    tenants: { ulid: string; name: string }[];
    tenant: { ulid: string; name: string; areas: TenantArea[] } | null;
};

/**
 * FL1 (specs/core/16): HQ switches areas on or off for every brokerage (or for a share of them, rolled out
 * gradually), and per brokerage, with seat limits for areas sold by the seat.
 */
export default function HqSwitches({ everyone, tenants, tenant }: Props) {
    const opts = { preserveScroll: true };

    return (
        <>
            <Head title="Feature switches" />
            <div className="mx-auto grid w-full max-w-5xl gap-8 p-4 md:p-6">
                <PageHeader
                    title="Feature switches"
                    description="Switch an area on or off for every brokerage, roll it out to a share of brokerages, or set it for one brokerage. A brokerage's own setting wins over the everyone setting."
                />

                <section className="grid gap-2 text-sm">
                    <h2 className="text-base font-semibold">Every brokerage</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[640px]">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="p-2">Area</th>
                                    <th className="p-2">Setting</th>
                                    <th className="p-2">Roll out to</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {everyone.map((a) => (
                                    <EveryoneRow
                                        key={
                                            a.key +
                                            (a.hq_state ?? '') +
                                            (a.rollout_percent ?? '')
                                        }
                                        area={a}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="grid gap-3 text-sm">
                    <h2 className="text-base font-semibold">One brokerage</h2>
                    <select
                        aria-label="Brokerage"
                        className="h-10 max-w-sm rounded-md border bg-background px-3"
                        value={tenant?.ulid ?? ''}
                        onChange={(e) =>
                            router.get(
                                '/hq/switches',
                                e.target.value
                                    ? { tenant: e.target.value }
                                    : {},
                                opts,
                            )
                        }
                    >
                        <option value="">Choose a brokerage…</option>
                        {tenants.map((t) => (
                            <option key={t.ulid} value={t.ulid}>
                                {t.name}
                            </option>
                        ))}
                    </select>
                    {tenant && (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[640px]">
                                <thead className="text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="p-2">Area</th>
                                        <th className="p-2">Now</th>
                                        <th className="p-2">HQ setting</th>
                                        <th className="p-2">Seats</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {tenant.areas.map((a) => (
                                        <tr key={a.key}>
                                            <td className="p-2">{a.label}</td>
                                            <td className="p-2">
                                                <Badge
                                                    variant={
                                                        a.on
                                                            ? 'secondary'
                                                            : 'outline'
                                                    }
                                                >
                                                    {a.on ? 'On' : 'Off'}
                                                </Badge>
                                                {a.brokerage_off && (
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        switched off by the
                                                        brokerage
                                                    </span>
                                                )}
                                            </td>
                                            <td className="p-2">
                                                <select
                                                    aria-label={`${a.label} for ${tenant.name}`}
                                                    className="h-9 rounded-md border bg-background px-2"
                                                    value={a.hq_state ?? ''}
                                                    onChange={(e) =>
                                                        router.post(
                                                            `/hq/switches/tenants/${tenant.ulid}`,
                                                            {
                                                                area: a.key,
                                                                state:
                                                                    e.target
                                                                        .value ||
                                                                    null,
                                                            },
                                                            opts,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Same as everyone
                                                    </option>
                                                    <option value="on">
                                                        On
                                                    </option>
                                                    <option value="off">
                                                        Off
                                                    </option>
                                                </select>
                                            </td>
                                            <td className="p-2">
                                                {a.seats_possible ? (
                                                    <SeatInput
                                                        tenant={tenant.ulid}
                                                        area={a}
                                                    />
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Not by the seat
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

function EveryoneRow({ area }: { area: Everyone }) {
    const [percent, setPercent] = useState(
        area.rollout_percent?.toString() ?? '',
    );
    const opts = { preserveScroll: true };
    const save = (state: string | null, rollout: string) =>
        router.post(
            '/hq/switches',
            {
                area: area.key,
                state,
                rollout_percent: rollout === '' ? null : Number(rollout),
            },
            opts,
        );

    return (
        <tr>
            <td className="p-2">
                <span className="font-medium">{area.label}</span>
                <span className="block text-xs text-muted-foreground">
                    {area.about}
                </span>
            </td>
            <td className="p-2">
                <select
                    aria-label={`${area.label} for every brokerage`}
                    className="h-9 rounded-md border bg-background px-2"
                    value={area.hq_state ?? ''}
                    onChange={(e) => save(e.target.value || null, percent)}
                >
                    <option value="">
                        Default ({area.default_on ? 'on' : 'off'})
                    </option>
                    <option value="on">On</option>
                    <option value="off">Off</option>
                </select>
            </td>
            <td className="p-2">
                <div className="flex items-center gap-2">
                    <Input
                        aria-label={`${area.label} roll-out percent`}
                        className="w-20"
                        inputMode="numeric"
                        placeholder="All"
                        value={percent}
                        onChange={(e) =>
                            setPercent(
                                e.target.value.replace(/\D/g, '').slice(0, 3),
                            )
                        }
                    />
                    <span>%</span>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => save(area.hq_state, percent)}
                    >
                        Save
                    </Button>
                </div>
            </td>
        </tr>
    );
}

function SeatInput({ tenant, area }: { tenant: string; area: TenantArea }) {
    const [seats, setSeats] = useState(area.seats?.toString() ?? '');

    return (
        <div className="flex items-center gap-2">
            <Input
                aria-label={`${area.label} seats`}
                className="w-24"
                inputMode="numeric"
                placeholder="No limit"
                value={seats}
                onChange={(e) =>
                    setSeats(e.target.value.replace(/\D/g, '').slice(0, 6))
                }
            />
            <Button
                size="sm"
                variant="outline"
                onClick={() =>
                    router.post(
                        `/hq/switches/tenants/${tenant}/seats`,
                        {
                            area: area.key,
                            seats: seats === '' ? null : Number(seats),
                        },
                        { preserveScroll: true },
                    )
                }
            >
                Save
            </Button>
        </div>
    );
}
