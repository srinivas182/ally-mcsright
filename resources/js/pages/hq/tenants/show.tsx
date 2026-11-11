import { ViewAsDialog } from '@/components/view-as-dialog';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

import { formatDate, StatusBadge, type TenantStatus } from './components';

type Tenant = {
    ulid: string;
    name: string;
    slug: string;
    type: string;
    status: TenantStatus;
    state_code: string | null;
    timezone: string;
    cell: string | null;
    suspended_reason: string | null;
    closing_at: string | null;
    closing_reason: string | null;
    closed_at: string | null;
    purge_after: string | null;
    created_at: string | null;
    is_hq: boolean;
};

type Domain = {
    host: string;
    kind: string;
    is_primary: boolean;
    verification_status: string;
    ssl_status: string;
};

type Props = {
    tenant: Tenant;
    domains: Domain[];
    redirects: { host: string; until: string }[];
};

type Action = 'suspend' | 'close' | null;

const actionCopy = {
    suspend: {
        title: 'Suspend tenant',
        description:
            'Users will see a suspension notice. Data is not changed and the tenant can be reactivated at any time.',
        button: 'Suspend',
    },
    close: {
        title: 'Close tenant',
        description:
            'Starts a 30-day closing period that can be reversed. After that the tenant is closed and its data is kept for 90 more days.',
        button: 'Start closing',
    },
};

function Row({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="grid grid-cols-3 gap-2 py-2 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="col-span-2">{value}</dd>
        </div>
    );
}

export default function ShowTenant({ tenant, domains, redirects }: Props) {
    const [action, setAction] = useState<Action>(null);
    const reasonForm = useForm({ reason: '' });
    const slugForm = useForm({ slug: tenant.slug });
    const reactivateForm = useForm({});
    const base = `/hq/tenants/${tenant.ulid}`;
    const [support, setSupport] = useState<string | null>(null);

    const canSuspend = !tenant.is_hq && tenant.status === 'active';
    const canClose =
        !tenant.is_hq && ['active', 'suspended'].includes(tenant.status);
    const canReactivate = ['suspended', 'closing'].includes(tenant.status);

    return (
        <>
            <Head title={tenant.name} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="grid gap-1">
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-semibold">
                                {tenant.name}
                            </h1>
                            <StatusBadge status={tenant.status} />
                        </div>
                        <p className="text-sm text-muted-foreground capitalize">
                            {tenant.type}
                            {tenant.state_code ? ` · ${tenant.state_code}` : ''}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {canReactivate && (
                            <Button
                                disabled={reactivateForm.processing}
                                onClick={() =>
                                    reactivateForm.post(`${base}/reactivate`)
                                }
                            >
                                Reactivate
                            </Button>
                        )}
                        {canSuspend && (
                            <Button
                                variant="outline"
                                onClick={() => setAction('suspend')}
                            >
                                Suspend
                            </Button>
                        )}
                        {canClose && (
                            <Button
                                variant="destructive"
                                onClick={() => setAction('close')}
                            >
                                Close
                            </Button>
                        )}
                    </div>
                </header>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="divide-y">
                                <Row label="Address" value={tenant.slug} />
                                <Row label="Cell" value={tenant.cell ?? '—'} />
                                <Row
                                    label="Time zone"
                                    value={tenant.timezone}
                                />
                                <Row
                                    label="Created"
                                    value={formatDate(tenant.created_at)}
                                />
                                {tenant.suspended_reason && (
                                    <Row
                                        label="Suspended because"
                                        value={tenant.suspended_reason}
                                    />
                                )}
                                {tenant.closing_at && (
                                    <Row
                                        label="Closing since"
                                        value={`${formatDate(tenant.closing_at)} — ${tenant.closing_reason ?? ''}`}
                                    />
                                )}
                                {tenant.purge_after && (
                                    <Row
                                        label="Data kept until"
                                        value={formatDate(tenant.purge_after)}
                                    />
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Domains</CardTitle>
                            <CardDescription>
                                Where this tenant is reachable.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {domains.map((domain) => (
                                <div
                                    key={domain.host}
                                    className="flex flex-wrap items-center justify-between gap-2 text-sm"
                                >
                                    <span className="font-medium">
                                        {domain.host}
                                    </span>
                                    <span className="flex gap-2">
                                        {domain.is_primary && (
                                            <Badge>Primary</Badge>
                                        )}
                                        <Badge
                                            variant="outline"
                                            className="capitalize"
                                        >
                                            {domain.kind}
                                        </Badge>
                                        <Badge
                                            variant="secondary"
                                            className="capitalize"
                                        >
                                            {domain.verification_status}
                                        </Badge>
                                    </span>
                                </div>
                            ))}
                            {redirects.map((redirect) => (
                                <p
                                    key={redirect.host}
                                    className="text-sm text-muted-foreground"
                                >
                                    {redirect.host} redirects here until{' '}
                                    {formatDate(redirect.until)}
                                </p>
                            ))}
                        </CardContent>
                    </Card>

                    {!tenant.is_hq && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Change address</CardTitle>
                                <CardDescription>
                                    The old address keeps redirecting for 90
                                    days.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form
                                    className="flex flex-wrap items-start gap-3"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        slugForm.post(`${base}/slug`);
                                    }}
                                >
                                    <FormField
                                        label="New address"
                                        error={slugForm.errors.slug}
                                        className="min-w-60 flex-1"
                                    >
                                        {(control) => (
                                            <Input
                                                {...control}
                                                value={slugForm.data.slug}
                                                onChange={(event) =>
                                                    slugForm.setData(
                                                        'slug',
                                                        event.target.value.toLowerCase(),
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>
                                    <Button
                                        type="submit"
                                        variant="secondary"
                                        className="mt-6"
                                        disabled={
                                            slugForm.processing ||
                                            slugForm.data.slug === tenant.slug
                                        }
                                    >
                                        Change
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>

            <Dialog
                open={action !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setAction(null);
                        reasonForm.reset();
                        reasonForm.clearErrors();
                    }
                }}
            >
                {action && (
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {actionCopy[action].title}
                            </DialogTitle>
                            <DialogDescription>
                                {actionCopy[action].description}
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            id="reason-form"
                            onSubmit={(event) => {
                                event.preventDefault();
                                reasonForm.post(`${base}/${action}`, {
                                    onSuccess: () => setAction(null),
                                });
                            }}
                        >
                            <FormField
                                label="Reason"
                                help="Recorded in the audit log."
                                error={reasonForm.errors.reason}
                                required
                            >
                                {(control) => (
                                    <Textarea
                                        {...control}
                                        value={reasonForm.data.reason}
                                        onChange={(event) =>
                                            reasonForm.setData(
                                                'reason',
                                                event.target.value,
                                            )
                                        }
                                    />
                                )}
                            </FormField>
                        </form>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setAction(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                form="reason-form"
                                variant="destructive"
                                disabled={reasonForm.processing}
                            >
                                {actionCopy[action].button}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                )}
            </Dialog>
            <section className="mx-4 mb-6 grid max-w-xl gap-2 rounded-lg border p-4 md:mx-6">
                <h2 className="font-semibold">Support: view as a user</h2>
                <p className="text-sm text-muted-foreground">
                    Opens the brokerage as that user for up to 60 minutes. They
                    are emailed your reason.
                </p>
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const email = (
                            e.currentTarget.elements.namedItem(
                                'email',
                            ) as HTMLInputElement
                        ).value;
                        setSupport(email);
                    }}
                >
                    <input
                        name="email"
                        type="email"
                        required
                        aria-label="User email"
                        placeholder="user@brokerage.com"
                        className="min-w-64 flex-1 rounded-md border bg-background px-3 py-2 text-sm"
                    />
                    <button
                        type="submit"
                        className="rounded-md border px-3 py-2 text-sm"
                    >
                        Continue
                    </button>
                </form>
            </section>
            {support && (
                <ViewAsDialog
                    name={support}
                    url={`${base}/impersonate`}
                    extra={{ email: support }}
                    onClose={() => setSupport(null)}
                />
            )}
        </>
    );
}

ShowTenant.layout = {
    breadcrumbs: [{ title: 'Tenants', href: '/hq/tenants' }],
};
