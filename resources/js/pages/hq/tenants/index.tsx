import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Pagination } from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

import { formatDate, StatusBadge, type TenantStatus } from './components';

type TenantRow = {
    ulid: string;
    name: string;
    slug: string;
    type: 'hq' | 'brokerage' | 'solo';
    status: TenantStatus;
    cell: string | null;
    created_at: string | null;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    tenants: Paginated<TenantRow>;
    filters: { search: string; type: string; status: string };
};

const ALL = 'all';

export default function TenantsIndex({ tenants, filters }: Props) {
    const [search, setSearch] = useState(filters.search);

    function apply(next: Partial<Props['filters']>) {
        const query = { ...filters, search, ...next };
        router.get(
            '/hq/tenants',
            Object.fromEntries(
                Object.entries(query).filter(([, v]) => v !== '' && v !== ALL),
            ),
            { preserveState: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Tenants" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Tenants</h1>
                        <p className="text-sm text-muted-foreground">
                            {tenants.total} brokerages and solo workspaces
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/hq/tenants/create">New tenant</Link>
                    </Button>
                </header>

                <form
                    className="flex flex-wrap gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply({});
                    }}
                >
                    <Input
                        aria-label="Search tenants"
                        placeholder="Search name or address"
                        className="max-w-xs"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                    <Select
                        value={filters.type || ALL}
                        onValueChange={(type) => apply({ type })}
                    >
                        <SelectTrigger
                            aria-label="Filter by type"
                            className="w-40"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All types</SelectItem>
                            <SelectItem value="brokerage">Brokerage</SelectItem>
                            <SelectItem value="solo">Solo agent</SelectItem>
                            <SelectItem value="hq">HQ</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.status || ALL}
                        onValueChange={(status) => apply({ status })}
                    >
                        <SelectTrigger
                            aria-label="Filter by status"
                            className="w-40"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All statuses</SelectItem>
                            {[
                                'active',
                                'provisioning',
                                'suspended',
                                'closing',
                                'closed',
                            ].map((s) => (
                                <SelectItem
                                    key={s}
                                    value={s}
                                    className="capitalize"
                                >
                                    {s}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button type="submit" variant="secondary">
                        Search
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Name</th>
                                <th className="p-3 font-medium">Address</th>
                                <th className="p-3 font-medium">Type</th>
                                <th className="p-3 font-medium">Status</th>
                                <th className="p-3 font-medium">Cell</th>
                                <th className="p-3 font-medium">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            {tenants.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        No tenants match these filters.
                                    </td>
                                </tr>
                            )}
                            {tenants.data.map((tenant) => (
                                <tr
                                    key={tenant.ulid}
                                    className="border-t hover:bg-muted/30"
                                >
                                    <td className="p-3">
                                        <Link
                                            href={`/hq/tenants/${tenant.ulid}`}
                                            className="font-medium text-primary hover:underline"
                                        >
                                            {tenant.name}
                                        </Link>
                                    </td>
                                    <td className="p-3 text-muted-foreground">
                                        {tenant.slug}
                                    </td>
                                    <td className="p-3 capitalize">
                                        {tenant.type}
                                    </td>
                                    <td className="p-3">
                                        <StatusBadge status={tenant.status} />
                                    </td>
                                    <td className="p-3">
                                        {tenant.cell ?? '—'}
                                    </td>
                                    <td className="p-3">
                                        {formatDate(tenant.created_at)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    page={tenants.current_page}
                    lastPage={tenants.last_page}
                    onPageChange={(page) =>
                        router.get(window.location.pathname, {
                            ...Object.fromEntries(
                                new URLSearchParams(window.location.search),
                            ),
                            page,
                        })
                    }
                />
            </div>
        </>
    );
}

TenantsIndex.layout = {
    breadcrumbs: [{ title: 'Tenants', href: '/hq/tenants' }],
};
