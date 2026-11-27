import {
    Check,
    Copy,
    Facebook,
    Linkedin,
    Mail,
    MessageCircle,
    Twitter,
} from 'lucide-react';
import { useState } from 'react';

type Joins = {
    opens: number;
    opens_30: number;
    started: number;
    joined: number;
};

/**
 * AG1b: the top of an agent's Overview: their join link, made to stand out. Copy it in one tap, share it, and see
 * at a glance what it brought (opened, started, joined). Revenue share is only promised when the agent's plan pays it.
 */
export function ReferralHero({
    url,
    joins,
    revenueShare,
}: {
    url: string;
    joins: Joins;
    revenueShare: boolean;
}) {
    const [copied, setCopied] = useState(false);
    const text = encodeURIComponent(
        'Come and join my brokerage. Sign up with my link:',
    );
    const link = encodeURIComponent(url);
    const shares = [
        {
            name: 'WhatsApp',
            icon: MessageCircle,
            href: `https://wa.me/?text=${text}%20${link}`,
        },
        {
            name: 'Facebook',
            icon: Facebook,
            href: `https://www.facebook.com/sharer/sharer.php?u=${link}`,
        },
        {
            name: 'LinkedIn',
            icon: Linkedin,
            href: `https://www.linkedin.com/sharing/share-offsite/?url=${link}`,
        },
        {
            name: 'X',
            icon: Twitter,
            href: `https://x.com/intent/post?text=${text}&url=${link}`,
        },
        {
            name: 'Email',
            icon: Mail,
            href: `mailto:?subject=${encodeURIComponent('Join my brokerage')}&body=${text}%20${link}`,
        },
    ];
    const results: [string, number][] = [
        ['Opened your link', joins.opens],
        ['Started joining', joins.started],
        ['Joined under you', joins.joined],
    ];

    return (
        <section
            aria-labelledby="referral-hero"
            className="relative overflow-hidden rounded-2xl bg-primary p-6 text-primary-foreground shadow-lg md:p-8"
        >
            <div
                aria-hidden="true"
                className="pointer-events-none absolute -top-24 -right-24 size-72 rounded-full bg-white/10"
            />
            <div
                aria-hidden="true"
                className="pointer-events-none absolute -bottom-32 left-1/3 size-80 rounded-full bg-white/5"
            />
            <div className="relative grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:items-center">
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <h2
                            id="referral-hero"
                            className="text-2xl font-semibold tracking-tight md:text-3xl"
                        >
                            Invite agents you would love to work with
                        </h2>
                        <p className="max-w-xl text-base text-primary-foreground/85">
                            Anyone who joins through your link is placed under
                            you in your network
                            {revenueShare
                                ? ', and you earn revenue share every time they close a deal.'
                                : '.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-stretch gap-2">
                        <span className="flex min-w-0 flex-1 items-center rounded-xl bg-white/15 px-4 py-3 font-mono text-sm ring-1 ring-white/25 md:text-base">
                            <span className="truncate">{url}</span>
                        </span>
                        <button
                            type="button"
                            onClick={() => {
                                void navigator.clipboard?.writeText(url);
                                setCopied(true);
                                window.setTimeout(() => setCopied(false), 2000);
                            }}
                            className="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 font-semibold text-primary shadow-sm transition hover:bg-white/90 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-primary focus-visible:outline-none"
                        >
                            {copied ? (
                                <Check className="size-5" aria-hidden="true" />
                            ) : (
                                <Copy className="size-5" aria-hidden="true" />
                            )}
                            {copied ? 'Copied' : 'Copy my link'}
                        </button>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="mr-1 text-sm text-primary-foreground/80">
                            Share on
                        </span>
                        {shares.map(({ name, icon: Icon, href }) => (
                            <a
                                key={name}
                                href={href}
                                target={name === 'Email' ? undefined : '_blank'}
                                rel="noopener noreferrer"
                                aria-label={`Share on ${name}`}
                                title={name}
                                className="grid size-11 place-items-center rounded-full bg-white/15 ring-1 ring-white/25 transition hover:bg-white/25 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                            >
                                <Icon className="size-5" aria-hidden="true" />
                            </a>
                        ))}
                    </div>
                </div>
                <dl className="grid grid-cols-3 gap-3 rounded-xl bg-white/10 p-4 ring-1 ring-white/20">
                    {results.map(([label, value]) => (
                        <div key={label} className="grid gap-1 text-center">
                            <dd className="order-1 text-3xl font-semibold tabular-nums md:text-4xl">
                                {value}
                            </dd>
                            <dt className="order-2 text-xs text-primary-foreground/80 md:text-sm">
                                {label}
                            </dt>
                        </div>
                    ))}
                    <p className="col-span-3 text-center text-xs text-primary-foreground/75">
                        {joins.opens_30} opened in the last 30 days
                    </p>
                </dl>
            </div>
        </section>
    );
}
