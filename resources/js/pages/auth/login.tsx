import { useEffect, useRef } from 'react';
import { SsoButtons } from '@/components/sso-buttons';
import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import PasskeyVerify from '@/components/passkey-verify';

type Props = {
    status?: string;
    canResetPassword: boolean;
    ssoProviders?: string[];
    email?: string | null;
    elsewhere?: {
        finder: string;
        brokerages: { name: string; url: string }[];
    } | null;
};

export default function Login({
    status,
    canResetPassword,
    ssoProviders = [],
    email = null,
    elsewhere = null,
}: Props) {
    // LOGIN1: arriving with the email filled in, the cursor waits in the password box.
    const passwordRef = useRef<HTMLInputElement>(null);
    useEffect(() => {
        if (email) {
            passwordRef.current?.focus();
        }
    }, [email]);

    return (
        <>
            <Head title="Log in" />

            <PasskeyVerify />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                    defaultValue={email ?? undefined}
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">Password</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                        >
                                            Forgot your password?
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="current-password"
                                    placeholder="Password"
                                    ref={passwordRef}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox id="remember" name="remember" />
                                <Label htmlFor="remember">Remember me</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Log in
                            </Button>
                        </div>

                        {elsewhere && elsewhere.brokerages.length > 0 && (
                            <div
                                role="status"
                                className="grid gap-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"
                            >
                                <p>
                                    This page is for the RightAlly team. Your
                                    email signs in here:
                                </p>
                                {elsewhere.brokerages.map((b) => (
                                    <a
                                        key={b.url}
                                        href={b.url}
                                        className="font-semibold underline"
                                    >
                                        Continue to {b.name}
                                    </a>
                                ))}
                            </div>
                        )}
                        <p className="text-center text-sm text-muted-foreground">
                            {elsewhere ? (
                                <>
                                    With a brokerage?{' '}
                                    <a
                                        href={elsewhere.finder}
                                        className="underline"
                                    >
                                        Find your log in page
                                    </a>
                                </>
                            ) : (
                                'New here? Ask your brokerage to send you an invitation.'
                            )}
                        </p>
                    </>
                )}
            </Form>

            <SsoButtons providers={ssoProviders} />

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Welcome back',
    description: 'Log in with your email and password.',
};
