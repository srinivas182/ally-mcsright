import { Head } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Pack = {
    month: string;
    label: string;
    checked: number;
    flagged: number;
    channels: {
        key: string;
        label: string;
        checked: number;
        flagged: number;
        blocked: number;
        removed: number;
        warned: number;
    }[];
    items: {
        at: string;
        channel: string;
        action: string;
        phrases: string[];
        excerpt: string;
        where: string | null;
        by: string;
    }[];
};

const happened: Record<string, string> = {
    blocked: 'Stopped before sending',
    removed: 'Removed from the draft',
    warned: 'Warning shown',
};

/**
 * Settings → Fair Housing (specs/grow/12): one check on every channel, what it caught this month, and the monthly
 * audit packs as PDFs for the broker's records.
 */
export default function FairHousing({
    months,
    current,
}: {
    months: {
        month: string;
        label: string;
        checked: number;
        flagged: number;
    }[];
    current: Pack;
}) {
    return (
        <>
            <Head title="Fair Housing" />
            <div className="grid max-w-3xl gap-6 p-4 text-base">
                <PageHeader
                    title="Fair Housing"
                    description="Every ad, text, email, AIlly reply, website page, AI draft and template passes the same check. Here is what it caught, and a monthly audit pack for your records."
                />
                <section className="grid gap-2">
                    <h2 className="font-medium">
                        {current.label} so far:{' '}
                        {current.checked.toLocaleString()} checked,{' '}
                        {current.flagged.toLocaleString()} flagged
                    </h2>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-left text-muted-foreground">
                                <tr>
                                    <th className="py-1">Channel</th>
                                    <th>Checked</th>
                                    <th>Stopped</th>
                                    <th>Removed</th>
                                    <th>Warned</th>
                                </tr>
                            </thead>
                            <tbody>
                                {current.channels.map((c) => (
                                    <tr key={c.key} className="border-t">
                                        <td className="py-1">{c.label}</td>
                                        <td>{c.checked.toLocaleString()}</td>
                                        <td>{c.blocked}</td>
                                        <td>{c.removed}</td>
                                        <td>{c.warned}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {current.items.length > 0 && (
                        <ul className="grid gap-2 text-sm">
                            {current.items
                                .slice(-20)
                                .reverse()
                                .map((i, n) => (
                                    <li
                                        key={i.at + n}
                                        className="rounded-lg border p-2"
                                    >
                                        <p className="font-medium">
                                            {happened[i.action] ?? i.action} ·{' '}
                                            {i.channel}
                                            {i.where ? ` · ${i.where}` : ''}
                                        </p>
                                        <p className="text-muted-foreground">
                                            “{i.excerpt}” — “
                                            {i.phrases.join('”, “')}” · {i.by}
                                        </p>
                                    </li>
                                ))}
                        </ul>
                    )}
                </section>
                <section className="grid gap-2">
                    <h2 className="font-medium">Monthly audit packs</h2>
                    <p className="text-sm text-muted-foreground">
                        Each pack goes to owners by email on the 1st. Download
                        any month here.
                    </p>
                    <ul className="grid gap-1">
                        {months.map((m) => (
                            <li
                                key={m.month}
                                className="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span>
                                    {m.label}: {m.checked.toLocaleString()}{' '}
                                    checked, {m.flagged.toLocaleString()}{' '}
                                    flagged
                                </span>
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={`/settings/fair-housing/${m.month}.pdf`}
                                    >
                                        Download PDF
                                    </a>
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
