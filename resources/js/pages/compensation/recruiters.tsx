import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Plus } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Recruiter = {
    id: number;
    name: string;
    email: string;
    joined: boolean;
    recruits: number;
    closed: number;
    bonus_cents: number;
    paid_cents: number;
};

type Props = {
    recruiters: Recruiter[];
    settings: {
        bonus_cents: number;
        share_levels: number;
        confirmed_by: string | null;
        confirmed_at: string | null;
    };
    canManage: boolean;
};

/**
 * CM4b: recruiters. People the brokerage adds to bring in agents. They need no licence and never hold deals; they
 * are paid a flat bonus when a recruit closes their first deal, and revenue share only if the owner switches it on.
 */
export default function Recruiters({ recruiters, settings, canManage }: Props) {
    const [adding, setAdding] = useState(false);
    const add = useForm({
        first_name: '',
        last_name: '',
        email: '',
        bonus: '',
    });
    const pay = useForm({
        bonus: String(settings.bonus_cents / 100),
        share_levels: settings.share_levels,
        confirmed: settings.share_levels > 0,
    });

    return (
        <>
            <Head title="Recruiters" />
            <div className="flex max-w-5xl flex-col gap-5 p-4 md:p-6">
                <PageHeader
                    title="Recruiters"
                    description="People you add to bring agents to your brokerage. They need no licence and cannot be the agent on a deal."
                    actions={
                        canManage && (
                            <Button onClick={() => setAdding(!adding)}>
                                <Plus aria-hidden="true" /> Add a recruiter
                            </Button>
                        )
                    }
                />

                {adding && (
                    <form
                        className="grid gap-3 rounded-xl border bg-card p-4 text-sm sm:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            add.post('/compensation/recruiters', {
                                preserveScroll: true,
                                onSuccess: () => {
                                    add.reset();
                                    setAdding(false);
                                },
                            });
                        }}
                    >
                        <label className="grid gap-1">
                            First name
                            <Input
                                value={add.data.first_name}
                                onChange={(e) =>
                                    add.setData('first_name', e.target.value)
                                }
                                required
                            />
                        </label>
                        <label className="grid gap-1">
                            Last name
                            <Input
                                value={add.data.last_name}
                                onChange={(e) =>
                                    add.setData('last_name', e.target.value)
                                }
                                required
                            />
                        </label>
                        <label className="grid gap-1">
                            Email
                            <Input
                                type="email"
                                value={add.data.email}
                                onChange={(e) =>
                                    add.setData('email', e.target.value)
                                }
                                required
                            />
                        </label>
                        <label className="grid gap-1">
                            Their bonus per recruit ($, leave empty for your
                            default of {usd(settings.bonus_cents)})
                            <Input
                                type="number"
                                min={0}
                                value={add.data.bonus}
                                onChange={(e) =>
                                    add.setData('bonus', e.target.value)
                                }
                            />
                        </label>
                        {Object.values(add.errors)[0] && (
                            <p
                                role="alert"
                                className="text-destructive sm:col-span-2"
                            >
                                {Object.values(add.errors)[0]}
                            </p>
                        )}
                        <div className="sm:col-span-2">
                            <Button type="submit" disabled={add.processing}>
                                Add and send the invitation
                            </Button>
                        </div>
                    </form>
                )}

                <div className="overflow-x-auto rounded-xl border bg-card">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    Recruiter
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Agents brought in
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Who closed a deal
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Bonus per recruit
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Paid so far
                                </th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {recruiters.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No recruiters yet. Press &quot;Add a
                                        recruiter&quot; to add one.
                                    </td>
                                </tr>
                            )}
                            {recruiters.map((r) => (
                                <tr key={r.id} className="border-t">
                                    <td className="px-4 py-3">
                                        <span className="font-semibold">
                                            {r.name}
                                        </span>
                                        {!r.joined && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                Invited
                                            </Badge>
                                        )}
                                        <span className="block text-muted-foreground">
                                            {r.email}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {r.recruits}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {r.closed}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {usd(r.bonus_cents)}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {usd(r.paid_cents)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {canManage && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.delete(
                                                        `/compensation/recruiters/${r.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <section
                    aria-labelledby="recruiter-pay"
                    className="grid gap-4 rounded-xl border bg-card p-5 text-[15px]"
                >
                    <h2 id="recruiter-pay" className="text-lg font-semibold">
                        How recruiters are paid
                    </h2>
                    <label className="flex flex-wrap items-center gap-2">
                        A flat bonus of $
                        <Input
                            aria-label="Default bonus per recruit ($)"
                            type="number"
                            min={0}
                            className="w-28"
                            value={pay.data.bonus}
                            disabled={!canManage}
                            onChange={(e) =>
                                pay.setData('bonus', e.target.value)
                            }
                        />
                        when someone they brought in closes their first deal.
                    </label>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 font-medium">
                            Revenue share for recruiters
                        </legend>
                        {[
                            [
                                0,
                                'Off: recruiters are paid the bonus only (recommended)',
                            ],
                            [1, 'Also level 1 revenue share'],
                            [2, 'Also levels 1 and 2 revenue share'],
                        ].map(([v, label]) => (
                            <label key={v} className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="share_levels"
                                    className="size-4"
                                    disabled={!canManage}
                                    checked={pay.data.share_levels === v}
                                    onChange={() =>
                                        pay.setData('share_levels', v as number)
                                    }
                                />
                                {label}
                            </label>
                        ))}
                    </fieldset>
                    {pay.data.share_levels > 0 && (
                        <div className="grid gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-950 dark:bg-amber-950/40 dark:text-amber-100">
                            <p className="flex gap-2">
                                <AlertTriangle
                                    className="mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                                In most US states a brokerage may share
                                commission only with licensed people. Turn this
                                on only if your state allows it and your lawyer
                                agrees.
                            </p>
                            <label className="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    className="mt-1 size-4"
                                    checked={pay.data.confirmed}
                                    onChange={(e) =>
                                        pay.setData(
                                            'confirmed',
                                            e.target.checked,
                                        )
                                    }
                                />
                                I confirm my state allows paying revenue share
                                to recruiters without a licence.
                            </label>
                        </div>
                    )}
                    {settings.confirmed_by && (
                        <p className="text-sm text-muted-foreground">
                            Revenue share for recruiters was switched on by{' '}
                            {settings.confirmed_by}.
                        </p>
                    )}
                    {Object.values(pay.errors)[0] && (
                        <p role="alert" className="text-destructive">
                            {Object.values(pay.errors)[0]}
                        </p>
                    )}
                    {canManage && (
                        <div>
                            <Button
                                onClick={() =>
                                    pay.post(
                                        '/compensation/recruiters/settings',
                                        {
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                disabled={pay.processing}
                            >
                                Save recruiter pay
                            </Button>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

Recruiters.layout = {
    breadcrumbs: [{ title: 'Recruiters', href: '/compensation/recruiters' }],
};
