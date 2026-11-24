import { Check, Copy, Link2, Mail, MessageCircle } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';

/**
 * CM4: "Refer an agent and earn revenue share": the agent's own join link, to copy or share. Whoever joins through
 * it (with a valid licence and their pack paid) is placed under this agent automatically.
 */
export function SponsorLink({ url }: { url: string }) {
    const [copied, setCopied] = useState(false);
    const text = encodeURIComponent(
        'Come and join my brokerage. Sign up with my link:',
    );
    const link = encodeURIComponent(url);
    const shares: [string, string][] = [
        ['Facebook', `https://www.facebook.com/sharer/sharer.php?u=${link}`],
        ['X', `https://x.com/intent/post?text=${text}&url=${link}`],
        [
            'LinkedIn',
            `https://www.linkedin.com/sharing/share-offsite/?url=${link}`,
        ],
        ['WhatsApp', `https://wa.me/?text=${text}%20${link}`],
    ];

    return (
        <section
            aria-labelledby="sponsor-link"
            className="grid gap-3 rounded-xl border bg-card p-5"
        >
            <div>
                <h2 id="sponsor-link" className="text-lg font-semibold">
                    Refer an agent and earn revenue share
                </h2>
                <p className="text-sm text-muted-foreground">
                    Share your link. Agents who join through it are placed under
                    you, and you earn revenue share when they close deals.
                </p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <div className="flex min-w-0 flex-1 items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2">
                    <Link2
                        className="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span className="truncate font-mono text-sm">{url}</span>
                </div>
                <Button
                    variant="outline"
                    onClick={() => {
                        void navigator.clipboard?.writeText(url);
                        setCopied(true);
                        window.setTimeout(() => setCopied(false), 2000);
                    }}
                >
                    {copied ? (
                        <Check aria-hidden="true" />
                    ) : (
                        <Copy aria-hidden="true" />
                    )}
                    {copied ? 'Copied' : 'Copy link'}
                </Button>
            </div>
            <div className="flex flex-wrap gap-2">
                {shares.map(([name, href]) => (
                    <Button key={name} asChild size="sm" variant="secondary">
                        <a
                            href={href}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {name === 'WhatsApp' && (
                                <MessageCircle aria-hidden="true" />
                            )}
                            Share on {name}
                        </a>
                    </Button>
                ))}
                <Button asChild size="sm" variant="secondary">
                    <a
                        href={`mailto:?subject=${encodeURIComponent('Join my brokerage')}&body=${text}%20${link}`}
                    >
                        <Mail aria-hidden="true" /> Email it
                    </a>
                </Button>
            </div>
        </section>
    );
}
