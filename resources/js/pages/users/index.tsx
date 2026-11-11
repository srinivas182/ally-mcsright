import { ViewAsDialog } from '@/components/view-as-dialog';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { MoreHorizontalIcon } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Pagination } from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type UserRow = {
    ulid: string;
    name: string;
    email: string;
    kind: string;
    kind_label: string;
    status: 'active' | 'invited' | 'suspended' | 'deactivated';
    last_active_at: string | null;
    is_me: boolean;
};

type InvitationRow = {
    ulid: string;
    email: string;
    name: string;
    kind_label: string;
    expires_at: string;
    expired: boolean;
};

type Props = {
    users: {
        data: UserRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    invitations: InvitationRow[];
    filters: { search: string; kind: string; status: string };
    kinds: { value: string; label: string }[];
    agents: { id: number; name: string }[];
    can: { invite: boolean; manage: boolean };
};

const ALL = 'all';

const statusVariant = {
    active: 'default',
    invited: 'secondary',
    suspended: 'destructive',
    deactivated: 'outline',
} as const;

function lastActive(value: string | null): string {
    if (!value) {
        return 'Never';
    }
    return new Date(value).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

export default function UsersIndex(props: Props) {
    const { users, invitations, filters, kinds, agents, can } = props;
    const [inviting, setInviting] = useState(false);
    const [search, setSearch] = useState(filters.search);
    const form = useForm({
        email: '',
        first_name: '',
        last_name: '',
        kind:
            kinds.find((k) => k.value === 'agent')?.value ??
            kinds[0]?.value ??
            '',
        assisted_user_id: '',
    });

    function apply(next: Partial<Props['filters']>) {
        const query = { ...filters, search, ...next };
        router.get(
            '/users',
            Object.fromEntries(
                Object.entries(query).filter(([, v]) => v !== '' && v !== ALL),
            ),
            { preserveState: true, replace: true },
        );
    }

    const viewer = usePage().props.auth.user as { kind?: string } | null;
    const isOwner = viewer?.kind === 'owner';
    const [viewAs, setViewAs] = useState<{ ulid: string; name: string } | null>(
        null,
    );
    const act = (path: string) =>
        router.post(path, {}, { preserveScroll: true });

    return (
        <>
            <Head title="People" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">People</h1>
                        <p className="text-sm text-muted-foreground">
                            {users.total} people can sign in here
                        </p>
                    </div>
                    {can.invite && (
                        <Button onClick={() => setInviting(true)}>
                            Invite someone
                        </Button>
                    )}
                </header>

                {invitations.length > 0 && (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <h2 className="text-sm font-medium">
                            Waiting to accept ({invitations.length})
                        </h2>
                        {invitations.map((invitation) => (
                            <div
                                key={invitation.ulid}
                                className="flex flex-wrap items-center justify-between gap-2 text-sm"
                            >
                                <span>
                                    {invitation.name || invitation.email}{' '}
                                    <span className="text-muted-foreground">
                                        {invitation.email} ·{' '}
                                        {invitation.kind_label}
                                    </span>{' '}
                                    {invitation.expired && (
                                        <Badge variant="secondary">
                                            Expired
                                        </Badge>
                                    )}
                                </span>
                                {can.invite && (
                                    <span className="flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                act(
                                                    `/users/invitations/${invitation.ulid}/resend`,
                                                )
                                            }
                                        >
                                            Resend
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                act(
                                                    `/users/invitations/${invitation.ulid}/revoke`,
                                                )
                                            }
                                        >
                                            Revoke
                                        </Button>
                                    </span>
                                )}
                            </div>
                        ))}
                    </section>
                )}

                <form
                    className="flex flex-wrap gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply({});
                    }}
                >
                    <Input
                        aria-label="Search people"
                        placeholder="Search name or email"
                        className="max-w-xs"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <Select
                        value={filters.kind || ALL}
                        onValueChange={(kind) => apply({ kind })}
                    >
                        <SelectTrigger
                            aria-label="Filter by role"
                            className="w-40"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Everyone</SelectItem>
                            {kinds.map((k) => (
                                <SelectItem key={k.value} value={k.value}>
                                    {k.label}
                                </SelectItem>
                            ))}
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
                            <SelectItem value={ALL}>Any status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="suspended">Suspended</SelectItem>
                            <SelectItem value="deactivated">
                                Deactivated
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Button type="submit" variant="secondary">
                        Search
                    </Button>
                </form>

                {users.data.length === 0 ? (
                    <EmptyState
                        title="No one matches"
                        description="Try another search, or invite someone new."
                    />
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {users.data.map((user) => (
                            <li
                                key={user.ulid}
                                className="flex flex-wrap items-center justify-between gap-3 p-4"
                            >
                                <div className="grid gap-0.5">
                                    <span className="font-medium">
                                        {user.name}
                                        {user.is_me && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                (you)
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {user.email} · {user.kind_label} · Last
                                        active {lastActive(user.last_active_at)}
                                    </span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Badge
                                        variant={statusVariant[user.status]}
                                        className="capitalize"
                                    >
                                        {user.status}
                                    </Badge>
                                    {can.manage && !user.is_me && (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Actions for ${user.name}`}
                                                >
                                                    <MoreHorizontalIcon />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {user.status === 'active' && (
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            act(
                                                                `/users/${user.ulid}/suspend`,
                                                            )
                                                        }
                                                    >
                                                        Suspend
                                                    </DropdownMenuItem>
                                                )}
                                                {user.status !== 'active' && (
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            act(
                                                                `/users/${user.ulid}/reactivate`,
                                                            )
                                                        }
                                                    >
                                                        Reactivate
                                                    </DropdownMenuItem>
                                                )}
                                                {isOwner &&
                                                    user.kind !== 'owner' &&
                                                    user.status ===
                                                        'active' && (
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                setViewAs(user)
                                                            }
                                                        >
                                                            Log in as
                                                        </DropdownMenuItem>
                                                    )}
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        act(
                                                            `/users/${user.ulid}/sign-out-everywhere`,
                                                        )
                                                    }
                                                >
                                                    Sign out everywhere
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        act(
                                                            `/users/${user.ulid}/security-reset`,
                                                        )
                                                    }
                                                >
                                                    Reset sign-in security
                                                </DropdownMenuItem>
                                                {user.status !==
                                                    'deactivated' && (
                                                    <DropdownMenuItem
                                                        className="text-destructive"
                                                        onSelect={() =>
                                                            act(
                                                                `/users/${user.ulid}/deactivate`,
                                                            )
                                                        }
                                                    >
                                                        Deactivate
                                                    </DropdownMenuItem>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination
                    page={users.current_page}
                    lastPage={users.last_page}
                    total={users.total}
                    onPageChange={(page) =>
                        router.get(
                            '/users',
                            { ...filters, page },
                            { preserveState: true },
                        )
                    }
                />
            </div>

            <Dialog open={inviting} onOpenChange={setInviting}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Invite someone</DialogTitle>
                        <DialogDescription>
                            They get an email with a link to set their password.
                            The link expires in 7 days.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        id="invite-form"
                        className="grid gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/users/invitations', {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setInviting(false);
                                    form.reset();
                                },
                            });
                        }}
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                label="First name"
                                error={form.errors.first_name}
                                required
                            >
                                {(control) => (
                                    <Input
                                        {...control}
                                        value={form.data.first_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'first_name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                )}
                            </FormField>
                            <FormField
                                label="Last name"
                                error={form.errors.last_name}
                                required
                            >
                                {(control) => (
                                    <Input
                                        {...control}
                                        value={form.data.last_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'last_name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                )}
                            </FormField>
                        </div>
                        <FormField
                            label="Email"
                            error={form.errors.email}
                            required
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    type="email"
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData('email', e.target.value)
                                    }
                                />
                            )}
                        </FormField>
                        <FormField label="Role" error={form.errors.kind}>
                            {(control) => (
                                <Select
                                    value={form.data.kind}
                                    onValueChange={(kind) =>
                                        form.setData('kind', kind)
                                    }
                                >
                                    <SelectTrigger {...control}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {kinds.map((k) => (
                                            <SelectItem
                                                key={k.value}
                                                value={k.value}
                                            >
                                                {k.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                        </FormField>
                        {form.data.kind === 'assistant' && (
                            <FormField
                                label="Assists"
                                help="The agent this assistant works for"
                                error={form.errors.assisted_user_id}
                                required
                            >
                                {(control) => (
                                    <Select
                                        value={form.data.assisted_user_id}
                                        onValueChange={(id) =>
                                            form.setData('assisted_user_id', id)
                                        }
                                    >
                                        <SelectTrigger {...control}>
                                            <SelectValue placeholder="Choose an agent" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {agents.map((a) => (
                                                <SelectItem
                                                    key={a.id}
                                                    value={String(a.id)}
                                                >
                                                    {a.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                            </FormField>
                        )}
                    </form>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setInviting(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="invite-form"
                            disabled={form.processing}
                        >
                            Send invitation
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {viewAs && (
                <ViewAsDialog
                    name={viewAs.name}
                    url={`/users/${viewAs.ulid}/impersonate`}
                    onClose={() => setViewAs(null)}
                />
            )}
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'People', href: '/users' }],
};
