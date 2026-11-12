import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

export type RoleOption = { ulid: string; name: string; locked: boolean };
export type Grant = { role: string; name: string; expires_at: string | null };

/** Give or remove roles; temporary access ends by itself on the chosen date. */
export function RoleAssignDialog({
    user,
    roles,
    grants,
    onClose,
}: {
    user: { ulid: string; name: string };
    roles: RoleOption[];
    grants: Grant[];
    onClose: () => void;
}) {
    const [role, setRole] = useState('');
    const [until, setUntil] = useState('');
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Roles for {user.name}</DialogTitle>
                    <DialogDescription>
                        Without any role, people keep the access of their type.
                    </DialogDescription>
                </DialogHeader>
                <ul className="divide-y rounded-md border text-sm">
                    {grants.length === 0 && (
                        <li className="p-2 text-muted-foreground">
                            No roles yet.
                        </li>
                    )}
                    {grants.map((g) => (
                        <li
                            key={g.role}
                            className="flex items-center justify-between p-2"
                        >
                            <span>
                                {g.name}
                                {g.expires_at && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · until{' '}
                                        {new Date(
                                            g.expires_at,
                                        ).toLocaleDateString()}
                                    </span>
                                )}
                            </span>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(
                                        `/users/${user.ulid}/roles/${g.role}`,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Remove
                            </Button>
                        </li>
                    ))}
                </ul>
                <form
                    className="grid gap-2 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        setError(null);
                        router.post(
                            `/users/${user.ulid}/roles`,
                            {
                                role,
                                expires_at: until || null,
                                reason: reason || null,
                            },
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setRole('');
                                    setUntil('');
                                    setReason('');
                                },
                                onError: (e) =>
                                    setError(Object.values(e)[0] ?? null),
                            },
                        );
                    }}
                >
                    <select
                        aria-label="Role"
                        className="rounded border bg-background px-2 py-1.5"
                        value={role}
                        onChange={(e) => setRole(e.target.value)}
                    >
                        <option value="">Choose a role…</option>
                        {roles
                            .filter(
                                (r) =>
                                    !r.locked &&
                                    !grants.some((g) => g.role === r.ulid),
                            )
                            .map((r) => (
                                <option key={r.ulid} value={r.ulid}>
                                    {r.name}
                                </option>
                            ))}
                    </select>
                    <label className="grid gap-1">
                        Ends on (optional, for temporary cover)
                        <Input
                            type="date"
                            value={until}
                            onChange={(e) => setUntil(e.target.value)}
                        />
                    </label>
                    <Input
                        aria-label="Reason"
                        placeholder="Covering for Maria during holiday"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    />
                    {error && (
                        <p role="alert" className="text-destructive">
                            {error}
                        </p>
                    )}
                    <Button type="submit" disabled={role === ''}>
                        Give role
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
