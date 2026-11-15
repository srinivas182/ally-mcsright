import { Head } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/page-header';

type Row = {
    key: string;
    name: string;
    category: string;
    status: 'available' | 'soon';
    brokerages: number;
    requests: number;
    last_at: string | null;
};

/** HQ → App demand: which apps brokerages ask for, so we build the most wanted first. */
export default function HqApps({ apps }: { apps: Row[] }) {
    return (
        <>
            <Head title="App demand" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="App demand"
                    description="How many brokerages asked for each app on their Apps page. Build the most wanted first."
                />
                <table className="w-full text-left text-sm">
                    <thead className="text-muted-foreground">
                        <tr>
                            <th className="py-2 font-normal">App</th>
                            <th className="py-2 font-normal">Category</th>
                            <th className="py-2 font-normal">Brokerages</th>
                            <th className="py-2 font-normal">Requests</th>
                            <th className="py-2 font-normal">Last asked</th>
                        </tr>
                    </thead>
                    <tbody>
                        {apps.map((a) => (
                            <tr key={a.key} className="border-t">
                                <td className="py-2">
                                    {a.name}{' '}
                                    {a.status === 'available' && (
                                        <Badge variant="outline">Live</Badge>
                                    )}
                                </td>
                                <td className="py-2">{a.category}</td>
                                <td className="py-2">{a.brokerages}</td>
                                <td className="py-2">{a.requests}</td>
                                <td className="py-2">
                                    {a.last_at
                                        ? new Date(
                                              a.last_at,
                                          ).toLocaleDateString()
                                        : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}

HqApps.layout = {
    breadcrumbs: [{ title: 'App demand', href: '/hq/apps' }],
};
