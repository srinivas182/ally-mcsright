import { Form, Head, router, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { store } from '@/routes/two-factor/login';

const HELP_MESSAGES: Record<string, string> = {
    'sign-in-help-asked':
        'We asked your broker to help. They will call you to check it is really you, then you will get an email to set up your sign-in again.',
    'sign-in-help-support':
        'There is no one else in your brokerage who can reset your sign-in. Please contact RightAlly support and we will help you.',
};

export default function TwoFactorChallenge({
    status,
}: {
    status?: string | null;
}) {
    const [asking, setAsking] = useState(false);
    const helpMessage = status ? HELP_MESSAGES[status] : undefined;

    const [showRecoveryInput, setShowRecoveryInput] = useState<boolean>(false);
    const [code, setCode] = useState<string>('');

    const authConfigContent = useMemo<{
        title: string;
        description: string;
        toggleText: string;
    }>(() => {
        if (showRecoveryInput) {
            return {
                title: 'Recovery code',
                description:
                    'Please confirm access to your account by entering one of your emergency recovery codes.',
                toggleText: 'use the code from my app instead',
            };
        }

        return {
            title: 'Type the code from your app',
            description:
                'Open the authenticator app on your phone and type the 6-digit code it shows.',
            toggleText: 'login using a recovery code',
        };
    }, [showRecoveryInput]);

    setLayoutProps({
        title: authConfigContent.title,
        description: authConfigContent.description,
    });

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
        setCode('');
    };

    return (
        <>
            <Head title="Two-step sign-in" />

            <div className="space-y-6">
                <Form
                    {...store.form()}
                    className="space-y-4"
                    resetOnError
                    resetOnSuccess={!showRecoveryInput}
                >
                    {({ errors, processing, clearErrors }) => (
                        <>
                            {showRecoveryInput ? (
                                <>
                                    <Input
                                        name="recovery_code"
                                        type="text"
                                        placeholder="Enter recovery code"
                                        required
                                    />
                                    <InputError
                                        message={errors.recovery_code}
                                    />
                                </>
                            ) : (
                                <div className="flex flex-col items-center justify-center space-y-3 text-center">
                                    <div className="flex w-full items-center justify-center">
                                        <InputOTP
                                            name="code"
                                            maxLength={OTP_MAX_LENGTH}
                                            value={code}
                                            onChange={(value) => setCode(value)}
                                            disabled={processing}
                                            pattern={REGEXP_ONLY_DIGITS}
                                        >
                                            <InputOTPGroup>
                                                {Array.from(
                                                    { length: OTP_MAX_LENGTH },
                                                    (_, index) => (
                                                        <InputOTPSlot
                                                            key={index}
                                                            index={index}
                                                        />
                                                    ),
                                                )}
                                            </InputOTPGroup>
                                        </InputOTP>
                                    </div>
                                    <InputError message={errors.code} />
                                </div>
                            )}

                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="remember_device"
                                    value="1"
                                    className="size-4 rounded border"
                                />
                                Trust this device for 30 days
                            </label>

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                            >
                                Continue
                            </Button>

                            <div className="text-center text-sm text-muted-foreground">
                                <span>or you can </span>
                                <button
                                    type="button"
                                    className="cursor-pointer text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                    onClick={() =>
                                        toggleRecoveryMode(clearErrors)
                                    }
                                >
                                    {authConfigContent.toggleText}
                                </button>
                            </div>
                        </>
                    )}
                </Form>

                {/* SX3: lost the phone or key — the broker confirms it is really them, never email alone. */}
                <section className="grid gap-2 rounded-lg border p-4 text-base">
                    <h2 className="font-medium">Lost your phone?</h2>
                    {helpMessage ? (
                        <p role="status">{helpMessage}</p>
                    ) : (
                        <>
                            <p className="text-muted-foreground">
                                Ask your broker to reset your sign-in. They will
                                call you first to make sure it is you.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="lg"
                                disabled={asking}
                                onClick={() => {
                                    setAsking(true);
                                    router.post(
                                        '/two-factor-challenge/help',
                                        {},
                                        { onFinish: () => setAsking(false) },
                                    );
                                }}
                            >
                                Ask my broker for help
                            </Button>
                        </>
                    )}
                </section>
            </div>
        </>
    );
}
