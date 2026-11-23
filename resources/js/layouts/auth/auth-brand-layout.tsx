import { usePage } from '@inertiajs/react';
import { Check, ShieldCheck } from 'lucide-react';

import { Logo } from '@/components/app-logo';
import type { AuthLayoutProps } from '@/types';

/** What RightAlly does, said once beside every sign-in screen. */
const POINTS = [
    'Your website, CRM, deals and e-sign in one place',
    'Commissions and accounting that add up on their own',
    'An AI assistant that knows your brokerage',
];

/**
 * BR1: sign-in, invitation, password and two-step screens. A deep RightAlly-blue panel with the logo on the left
 * (wide screens), the form on a raised card on the right. On a brokerage's own address it names the brokerage.
 */
export default function AuthBrandLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { tenant } = usePage().props;
    const brokerage = tenant && !tenant.isHq ? tenant.name : null;
    // BR2: on a brokerage's own address, its photo, logo and colours; RightAlly is a small "powered by".
    const own = brokerage !== null && (tenant?.branded || tenant?.photo);

    return (
        <div className="grid min-h-svh bg-[#F4F6FB] lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] dark:bg-background">
            {own ? (
                <BrokerageAside
                    name={brokerage}
                    logo={tenant?.logo ?? null}
                    photo={tenant?.photo ?? null}
                />
            ) : (
                <aside className="relative hidden overflow-hidden bg-[#04163F] text-white lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-16">
                    {/* A faint plan-drawing grid and a glow of the brand blue: the drafting table a home starts on. */}
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-0 opacity-[0.14]"
                        style={{
                            backgroundImage:
                                'linear-gradient(to right, #8FB3FF 1px, transparent 1px), linear-gradient(to bottom, #8FB3FF 1px, transparent 1px)',
                            backgroundSize: '44px 44px',
                            maskImage:
                                'radial-gradient(ellipse at 30% 40%, black 20%, transparent 75%)',
                        }}
                    />
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute -right-40 -bottom-48 size-[36rem] rounded-full bg-[#155DFC] opacity-40 blur-[120px]"
                    />

                    <Logo className="relative text-[1.75rem] text-white" />

                    <div className="relative max-w-md">
                        <p className="text-[2.35rem] leading-[1.12] font-semibold tracking-[-0.02em] text-balance">
                            The whole brokerage, run from one calm place.
                        </p>
                        <ul className="mt-8 space-y-4 text-[1.05rem] text-[#C9D6F5]">
                            {POINTS.map((point) => (
                                <li key={point} className="flex gap-3">
                                    <span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/20">
                                        <Check className="size-3.5 text-white" />
                                    </span>
                                    {point}
                                </li>
                            ))}
                        </ul>
                    </div>

                    <p className="relative flex items-center gap-2 text-sm text-[#9FB2DE]">
                        <ShieldCheck className="size-4" />
                        Protected by two-step sign-in and encryption.
                    </p>
                </aside>
            )}

            <main className="flex flex-col items-center justify-center px-5 py-10 sm:px-8">
                <div className="w-full max-w-[26rem]">
                    {own ? (
                        <p className="mb-8 flex items-center gap-3 text-xl font-semibold lg:hidden">
                            {tenant?.logo && (
                                <img
                                    src={tenant.logo}
                                    alt=""
                                    className="size-10 rounded-lg bg-white object-contain p-1 ring-1 ring-border"
                                />
                            )}
                            {brokerage}
                        </p>
                    ) : (
                        <Logo className="mb-8 text-2xl text-[#09090B] lg:hidden dark:text-white" />
                    )}
                    <div className="rounded-2xl border border-[#E3E8F2] bg-white p-7 shadow-[0_1px_2px_rgb(6_22_63/0.04),0_12px_32px_-12px_rgb(6_22_63/0.18)] sm:p-9 dark:border-border dark:bg-card">
                        {brokerage && (
                            <p className="mb-4 inline-flex rounded-full bg-primary/10 px-3 py-1 text-sm font-medium text-primary dark:bg-primary/15">
                                {brokerage}
                            </p>
                        )}
                        {title && (
                            <h1 className="text-[1.6rem] leading-tight font-semibold tracking-[-0.015em] text-[#0A1330] dark:text-foreground">
                                {title}
                            </h1>
                        )}
                        {description && (
                            <p className="mt-2 text-[0.975rem] text-muted-foreground">
                                {description}
                            </p>
                        )}
                        <div
                            className={`auth-form ${title || description ? 'mt-7' : ''}`}
                        >
                            {children}
                        </div>
                    </div>
                    <p className="mt-6 text-center text-sm text-muted-foreground">
                        Need help signing in? Ask your broker, or the person who
                        invited you.
                    </p>
                </div>
            </main>
        </div>
    );
}

/** BR2: the sign-in panel in the brokerage's own look: its website photo under its colour, its logo and name. */
function BrokerageAside({
    name,
    logo,
    photo,
}: {
    name: string;
    logo: string | null;
    photo: string | null;
}) {
    const { platformUrl } = usePage().props;

    return (
        <aside className="relative hidden overflow-hidden bg-sidebar text-white lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-16">
            {photo && (
                <img
                    src={photo}
                    alt=""
                    aria-hidden="true"
                    className="absolute inset-0 size-full object-cover"
                />
            )}
            <div
                aria-hidden="true"
                className="absolute inset-0 bg-gradient-to-b from-sidebar/80 via-sidebar/55 to-sidebar/95"
            />
            <p className="relative flex items-center gap-3 text-xl font-semibold">
                {logo ? (
                    <img
                        src={logo}
                        alt=""
                        className="size-12 rounded-xl bg-white object-contain p-1.5 shadow-sm"
                    />
                ) : (
                    <span
                        aria-hidden="true"
                        className="flex size-12 items-center justify-center rounded-xl bg-sidebar-primary text-lg text-sidebar-primary-foreground"
                    >
                        {name
                            .split(/\s+/)
                            .slice(0, 2)
                            .map((w) => w[0])
                            .join('')
                            .toUpperCase()}
                    </span>
                )}
                {name}
            </p>
            <div className="relative max-w-md">
                <p className="text-[2.35rem] leading-[1.12] font-semibold tracking-[-0.02em] text-balance">
                    Welcome to {name}
                </p>
                <p className="mt-4 text-[1.05rem] text-white/85">
                    Your clients, listings, deals and messages in one place.
                </p>
            </div>
            <p className="relative flex items-center gap-2 text-sm text-white/80">
                <ShieldCheck className="size-4" />
                Protected by two-step sign-in and encryption. Powered by{' '}
                <a
                    href={platformUrl}
                    target="_blank"
                    rel="noopener"
                    aria-label="RightAlly (opens in a new tab)"
                    className="rounded-sm focus-visible:outline-2 focus-visible:outline-white"
                >
                    <Logo className="text-[0.95rem] text-white" />
                </a>
            </p>
        </aside>
    );
}
