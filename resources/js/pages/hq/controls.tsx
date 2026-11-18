import { Head } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Control = {
    id: string;
    area: string;
    control: string;
    evidence: string;
    status: 'met' | 'attention' | 'process';
};

type Props = { controls: Control[]; as_of: string };

const label = {
    met: 'Met',
    attention: 'Needs attention',
    process: 'Written process',
} as const;

/** TR1 (specs/core/18): SOC 2 controls with live evidence from the platform, ready to share with an auditor. */
export default function HqControls({ controls, as_of }: Props) {
    return (
        <>
            <Head title="Security controls" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Security controls (SOC 2)"
                    description={`What RightAlly does for each control and the evidence read from the platform as of ${new Date(as_of).toLocaleString()}. Counts only, never personal details.`}
                />
                <Button
                    asChild
                    variant="outline"
                    className="justify-self-start"
                >
                    <a href="/hq/controls/download">
                        Download for the auditor (CSV)
                    </a>
                </Button>
                <ul className="grid gap-3">
                    {controls.map((c) => (
                        <li
                            key={c.id}
                            className="grid gap-2 rounded-lg border p-4"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-sm text-muted-foreground">
                                    {c.id}
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    {c.area}
                                </span>
                                <Badge
                                    className="ml-auto"
                                    variant={
                                        c.status === 'attention'
                                            ? 'destructive'
                                            : c.status === 'met'
                                              ? 'default'
                                              : 'outline'
                                    }
                                >
                                    {label[c.status]}
                                </Badge>
                            </div>
                            <p className="font-medium">{c.control}</p>
                            <p className="text-muted-foreground">
                                {c.evidence}
                            </p>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}
