import { ViewAsDialog } from '@/components/view-as-dialog';
import { Head, router, useForm } from '@inertiajs/react';
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
            'Starts a 30-day closing period that can be reversed. After that the tenant is closed and its data is kept for 90 more days. A closed tenant can then be deleted permanently.',
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

export default function ShowTenant({
    tenant,
    domains,
    redirects,
    billing,
    assistant,
    licences,
}: Props & {
    billing: { exception: boolean; reason: string | null };
    assistant: Allowance | null;
    licences: HqLicence[];
}) {
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
    // TD1: a closed (or closing) brokerage can be deleted for good.
    const canPurge =
        !tenant.is_hq && ['closing', 'closed'].includes(tenant.status);
    const [purging, setPurging] = useState(false);
    const purgeForm = useForm({ confirm: '' });

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
                        <Button variant="outline" asChild>
                            <a href={`${base}/data`}>Data and backups</a>
                        </Button>
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
                        {canPurge && (
                            <Button
                                variant="destructive"
                                onClick={() => setPurging(true)}
                            >
                                Delete permanently
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
                open={purging}
                onOpenChange={(open) => {
                    setPurging(open);
                    if (!open) {
                        purgeForm.reset();
                        purgeForm.clearErrors();
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Delete {tenant.name} permanently?
                        </DialogTitle>
                        <DialogDescription>
                            This deletes all of its data: people, contacts,
                            deals, messages, files, its website and its
                            addresses. It cannot be undone. Download a copy
                            first under Data and backups if it may be needed.
                            The audit log is kept.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        id="purge-form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            purgeForm.post(`${base}/purge`);
                        }}
                    >
                        <FormField
                            label={`Type the address "${tenant.slug}" to confirm`}
                            error={purgeForm.errors.confirm}
                            required
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    autoComplete="off"
                                    value={purgeForm.data.confirm}
                                    onChange={(event) =>
                                        purgeForm.setData(
                                            'confirm',
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
                            onClick={() => setPurging(false)}
                        >
                            Keep it
                        </Button>
                        <Button
                            type="submit"
                            form="purge-form"
                            variant="destructive"
                            disabled={
                                purgeForm.processing ||
                                purgeForm.data.confirm !== tenant.slug
                            }
                        >
                            Delete permanently
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

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
            {licences.length > 0 && (
                <Licences ulid={tenant.ulid} licences={licences} />
            )}
            {assistant && (
                <AssistantLimit ulid={tenant.ulid} allowance={assistant} />
            )}
            <BillingException ulid={tenant.ulid} billing={billing} />
        </>
    );
}

ShowTenant.layout = {
    breadcrumbs: [{ title: 'Brokerages', href: '/hq/tenants' }],
};

function BillingException({
    ulid,
    billing,
}: {
    ulid: string;
    billing: { exception: boolean; reason: string | null };
}) {
    const [reason, setReason] = useState(billing.reason ?? '');

    return (
        <section className="mx-4 mb-6 grid max-w-xl gap-2 rounded-lg border p-4 text-sm md:mx-6">
            <h2 className="font-semibold">Who pays</h2>
            <p className="text-muted-foreground">
                {billing.exception
                    ? `This brokerage pays for its agents: ${billing.reason ?? ''}`
                    : 'Agents pay RightAlly directly (default). The brokerage has no liability.'}
            </p>
            {!billing.exception && (
                <input
                    aria-label="Reason"
                    placeholder="Signed brokerage agreement, 12 Jan"
                    className="rounded-md border bg-background px-3 py-2"
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                />
            )}
            <button
                type="button"
                className="justify-self-start rounded-md border px-3 py-2"
                onClick={() =>
                    router.post(
                        `/hq/tenants/${ulid}/billing-exception`,
                        { enabled: !billing.exception, reason },
                        { preserveScroll: true },
                    )
                }
            >
                {billing.exception
                    ? 'Return to agents pay'
                    : 'This brokerage pays for its agents'}
            </button>
        </section>
    );
}

type Allowance = { package: number; override: number | null; monthly: number };

/** AO1: the website assistant's monthly conversations for this brokerage: its package's, or its own number. */
function AssistantLimit({
    ulid,
    allowance,
}: {
    ulid: string;
    allowance: Allowance;
}) {
    const [value, setValue] = useState(
        allowance.override !== null ? String(allowance.override) : '',
    );
    const save = (monthly: string) =>
        router.post(
            `/hq/tenants/${ulid}/assistant-limit`,
            { monthly: monthly === '' ? null : Number(monthly) },
            { preserveScroll: true },
        );

    return (
        <section className="mx-4 mb-6 grid max-w-xl gap-2 rounded-lg border p-4 text-sm md:mx-6">
            <h2 className="font-semibold">Website assistant</h2>
            <p className="text-muted-foreground">
                {allowance.override !== null
                    ? `This brokerage has its own number: ${allowance.override.toLocaleString()} conversations a month (its package includes ${allowance.package.toLocaleString()}).`
                    : `Uses its package's number: ${allowance.package.toLocaleString()} conversations a month. The brokerage sets its own daily limits within this.`}
            </p>
            <form
                className="flex flex-wrap items-center gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    save(value);
                }}
            >
                <Input
                    type="number"
                    min={0}
                    inputMode="numeric"
                    aria-label="Conversations a month for this brokerage"
                    placeholder={String(allowance.package)}
                    className="w-40"
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                />
                <Button type="submit" variant="outline" size="sm">
                    Save
                </Button>
                {allowance.override !== null && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setValue('');
                            save('');
                        }}
                    >
                        Use the package&apos;s number
                    </Button>
                )}
            </form>
        </section>
    );
}

type HqLicence = {
    id: number;
    state: string;
    number: string;
    status: string;
    expires_on: string | null;
    hq_checked: boolean;
    good: boolean;
};

/** LV2: the brokerage's licences; HQ marks one as checked by hand with the state (new licences, states without records). */
function Licences({ ulid, licences }: { ulid: string; licences: HqLicence[] }) {
    return (
        <section className="mx-4 mb-6 grid max-w-xl gap-2 rounded-lg border p-4 text-sm md:mx-6">
            <h2 className="font-semibold">Licences</h2>
            {licences.map((l) => (
                <div
                    key={l.id}
                    className="flex flex-wrap items-center justify-between gap-2"
                >
                    <span>
                        {l.state} {l.number}: {l.status.replace('_', ' ')}
                        {l.expires_on ? `, expires ${l.expires_on}` : ''}
                        {l.hq_checked ? ', checked by HQ' : ''}
                    </span>
                    {!l.hq_checked &&
                        ['unchecked', 'new', 'not_found'].includes(
                            l.status,
                        ) && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/hq/tenants/${ulid}/licences/${l.id}/checked`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Mark as checked with the state
                            </Button>
                        )}
                </div>
            ))}
        </section>
    );
}
