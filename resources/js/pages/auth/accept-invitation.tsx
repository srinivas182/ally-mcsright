import { Head, useForm } from '@inertiajs/react';

import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    token: string;
    email: string;
    firstName: string;
    lastName: string;
    tenantName: string;
    kind: string;
};

export default function AcceptInvitation(props: Props) {
    const form = useForm({
        first_name: props.firstName,
        last_name: props.lastName,
        password: '',
        password_confirmation: '',
    });

    return (
        <>
            <Head title="Accept invitation" />
            <p className="mb-6 text-center text-sm text-muted-foreground">
                You're joining <strong>{props.tenantName}</strong> as{' '}
                {props.kind.toLowerCase()} with <strong>{props.email}</strong>.
            </p>
            <form
                className="flex flex-col gap-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/invitations/${props.token}`);
                }}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="first_name">First name</Label>
                        <Input
                            id="first_name"
                            required
                            autoComplete="given-name"
                            value={form.data.first_name}
                            onChange={(e) =>
                                form.setData('first_name', e.target.value)
                            }
                        />
                        <InputError message={form.errors.first_name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="last_name">Last name</Label>
                        <Input
                            id="last_name"
                            required
                            autoComplete="family-name"
                            value={form.data.last_name}
                            onChange={(e) =>
                                form.setData('last_name', e.target.value)
                            }
                        />
                        <InputError message={form.errors.last_name} />
                    </div>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="password">Create a password</Label>
                    <PasswordInput
                        id="password"
                        required
                        autoComplete="new-password"
                        value={form.data.password}
                        onChange={(e) =>
                            form.setData('password', e.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        At least 12 characters.
                    </p>
                    <InputError message={form.errors.password} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="password_confirmation">
                        Confirm password
                    </Label>
                    <PasswordInput
                        id="password_confirmation"
                        required
                        autoComplete="new-password"
                        value={form.data.password_confirmation}
                        onChange={(e) =>
                            form.setData(
                                'password_confirmation',
                                e.target.value,
                            )
                        }
                    />
                </div>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Join {props.tenantName}
                </Button>
            </form>
        </>
    );
}

AcceptInvitation.layout = {
    title: 'Welcome to RightAlly',
    description: 'Set up your account to get started',
};
