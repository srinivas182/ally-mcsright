import { router, useForm } from '@inertiajs/react';

import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

export type SessionRow = {
    id: string;
    label: string;
    ip: string | null;
    last_active: string;
    current: boolean;
};

export type TrustedDeviceRow = {
    id: number;
    label: string;
    expires_at: string;
};

const when = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

/** Where the user is signed in, and the devices that skip the 2FA prompt. */
export default function SignInActivity({
    sessions,
    trustedDevices,
}: {
    sessions: SessionRow[];
    trustedDevices: TrustedDeviceRow[];
}) {
    const form = useForm({ password: '' });
    const others = sessions.filter((s) => !s.current).length;

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Where you're signed in"
                description="Sign out of devices you don't recognise. Changing your password signs you out everywhere else."
            />
            <ul className="divide-y rounded-lg border">
                {sessions.map((session) => (
                    <li
                        key={session.id}
                        className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
                    >
                        <span>
                            <span className="font-medium">{session.label}</span>{' '}
                            {session.current && <Badge>This device</Badge>}
                            <span className="block text-muted-foreground">
                                {session.ip ?? 'Unknown IP'} · last active{' '}
                                {when(session.last_active)}
                            </span>
                        </span>
                        {!session.current && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.delete(
                                        `/settings/sessions/${session.id}`,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Sign out
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
            {others > 0 && (
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/settings/sessions/sign-out-others', {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <div className="grid gap-1">
                        <Label htmlFor="others-password">Your password</Label>
                        <PasswordInput
                            id="others-password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                        />
                        <InputError message={form.errors.password} />
                    </div>
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={form.processing}
                    >
                        Sign out of all other devices
                    </Button>
                </form>
            )}

            {trustedDevices.length > 0 && (
                <>
                    <Heading
                        variant="small"
                        title="Trusted devices"
                        description="These devices skip the two-step verification prompt for 30 days."
                    />
                    <ul className="divide-y rounded-lg border">
                        {trustedDevices.map((device) => (
                            <li
                                key={device.id}
                                className="flex items-center justify-between gap-2 p-3 text-sm"
                            >
                                <span>
                                    {device.label}
                                    <span className="block text-muted-foreground">
                                        Until {when(device.expires_at)}
                                    </span>
                                </span>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.delete(
                                            `/settings/trusted-devices/${device.id}`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </div>
    );
}
