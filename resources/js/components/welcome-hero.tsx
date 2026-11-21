import type { ReactNode } from 'react';

function greeting(hour: number): string {
    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 17 ? 'Good afternoon' : 'Good evening';
}

/**
 * BR2: the top of each home page: a greeting by time of day, today's date and one line on what the page holds,
 * on the RightAlly-blue band. Anything passed as children (set-up progress, who you are assisting) sits beneath.
 */
export function WelcomeHero({
    firstName,
    line,
    children,
}: {
    firstName: string;
    line?: ReactNode;
    children?: ReactNode;
}) {
    const now = new Date();
    const today = now.toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    });

    return (
        <section className="relative overflow-hidden rounded-2xl bg-[#061536] px-6 py-7 text-white shadow-[0_24px_50px_-28px_rgb(6_22_63/0.8)] md:px-9 md:py-9">
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 opacity-[0.12]"
                style={{
                    backgroundImage:
                        'linear-gradient(to right, #8FB3FF 1px, transparent 1px), linear-gradient(to bottom, #8FB3FF 1px, transparent 1px)',
                    backgroundSize: '40px 40px',
                    maskImage:
                        'linear-gradient(to left, black 10%, transparent 70%)',
                }}
            />
            <div
                aria-hidden="true"
                className="pointer-events-none absolute -top-32 -right-24 size-[28rem] rounded-full bg-[#155DFC] opacity-50 blur-[110px]"
            />
            <div className="relative">
                <p className="text-sm font-medium text-[#9FB2DE]">{today}</p>
                <h1 className="mt-1 text-[1.85rem] leading-tight font-semibold tracking-[-0.02em] md:text-[2.25rem]">
                    {greeting(now.getHours())}, {firstName}
                </h1>
                {line && (
                    <p className="mt-2 max-w-2xl text-[1.02rem] text-[#C9D6F5]">
                        {line}
                    </p>
                )}
                {children && <div className="mt-5">{children}</div>}
            </div>
        </section>
    );
}
