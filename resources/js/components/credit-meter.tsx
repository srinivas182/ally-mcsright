import { Link, usePage } from '@inertiajs/react';
import { Coins } from 'lucide-react';

import { Progress } from '@/components/ui/progress';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';

export type CreditMeterData = {
    available: number;
    total: number;
    used: number;
    scope: 'brokerage' | 'you';
    period: string;
    enforced: boolean;
    usage?: Record<string, { used: number; included: number; label: string }>;
};

/** "This month: 60/100 credits" with a small bar, at the foot of the menu. Links to Settings → Credits. */
export function CreditMeter() {
    const credits = usePage().props.credits as
        | CreditMeterData
        | null
        | undefined;
    const { state } = useSidebar();
    if (!credits) {
        return null;
    }
    const percent =
        credits.total > 0
            ? Math.round((credits.available * 100) / credits.total)
            : 0;
    // Before credits are switched on (and with nothing granted yet), show usage only.
    const usageOnly = !credits.enforced && credits.total <= credits.used;
    const low = !usageOnly && credits.total > 0 && percent <= 20;
    const label = usageOnly
        ? `${credits.used.toLocaleString()} RightAlly credits used this month`
        : `${credits.available.toLocaleString()} of ${credits.total.toLocaleString()} RightAlly credits left this month`;

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton
                    asChild
                    size="lg"
                    tooltip={label}
                    className="h-auto py-2"
                >
                    <Link href="/settings/credits" aria-label={label}>
                        <Coins
                            aria-hidden="true"
                            className={low ? 'text-destructive' : undefined}
                        />
                        {state !== 'collapsed' && (
                            <span className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="flex justify-between text-xs">
                                    <span className="text-muted-foreground">
                                        {credits.scope === 'you'
                                            ? 'Your credits'
                                            : 'Credits this month'}
                                    </span>
                                    <span
                                        className={
                                            low
                                                ? 'font-medium text-destructive'
                                                : 'font-medium'
                                        }
                                    >
                                        {usageOnly
                                            ? `${credits.used.toLocaleString()} used`
                                            : `${credits.available.toLocaleString()}/${credits.total.toLocaleString()}`}
                                    </span>
                                </span>
                                {!usageOnly && (
                                    <Progress
                                        value={percent}
                                        className="h-1.5"
                                        aria-hidden="true"
                                    />
                                )}
                                {credits.usage && (
                                    <span className="text-[11px] text-muted-foreground">
                                        {Object.entries(credits.usage)
                                            .filter(
                                                ([, u]) =>
                                                    u.included > 0 ||
                                                    u.used > 0,
                                            )
                                            .map(
                                                ([key, u]) =>
                                                    `${key === 'texts' ? 'Texts' : key === 'calls' ? 'Call min' : 'Map views'} ${u.used.toLocaleString()}/${u.included.toLocaleString()}`,
                                            )
                                            .join(' · ')}
                                    </span>
                                )}
                                {!usageOnly && credits.available <= 0 && (
                                    <span className="text-xs text-destructive">
                                        {credits.scope === 'you'
                                            ? 'Out of credits: ask your broker or buy more'
                                            : 'Out of credits: buy more'}
                                    </span>
                                )}
                            </span>
                        )}
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
