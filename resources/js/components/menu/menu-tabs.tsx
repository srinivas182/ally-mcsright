import { Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

import { GuidePanel, useGuide } from '@/components/menu/guide-panel';
import { useCurrentMenu } from '@/components/menu/current-menu';
import { AiMarker, PageTip } from '@/components/menu/page-hint';
import { PAGE_HINTS } from '@/components/menu/page-hints';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { ServerNavItem } from '@/types';

/** No page shows more than this many tabs in a row; the rest are under "More". */
const MAX_TABS = 7;

function Tab({ item, active }: { item: ServerNavItem; active: boolean }) {
    return (
        <Link
            href={item.href}
            prefetch
            aria-current={active ? 'page' : undefined}
            className={`-mb-px shrink-0 border-b-[3px] px-3 py-3 text-[15px] font-medium whitespace-nowrap transition-colors ${
                active
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground'
            }`}
        >
            {item.title}
        </Link>
    );
}

/**
 * MN3: the tabs of the main menu the page belongs to, under the top bar: at most 7 in a row (the rest under More), a
 * second row of pages where the menu has sections (Accounting, Settings), and on the right the menu's guide button
 * ("Close more deals"), which opens its help panel.
 */
export function MenuTabs() {
    const { tenant, simple } = usePage().props;
    const { group, item } = useCurrentMenu();
    const guide = useGuide(group?.key ?? null);
    const [open, setOpen] = useState<boolean | null>(null);
    const [start, setStart] = useState<{ lesson?: string; calling?: boolean }>(
        {},
    );

    if (tenant?.isHq || simple?.mode === 'simple' || group === null) {
        return null;
    }
    const sections = [
        ...new Set(group.items.map((i) => i.section).filter(Boolean)),
    ] as string[];
    const currentSection = item?.section ?? sections[0];
    const row =
        sections.length > 0
            ? group.items.filter((i) => i.section === currentSection)
            : group.items;
    let shown = row.slice(0, MAX_TABS);
    let more = row.slice(MAX_TABS);
    // The tab you are on is always visible.
    if (item && more.includes(item)) {
        shown = [...row.slice(0, MAX_TABS - 1), item];
        more = row.filter((i) => !shown.includes(i));
    }
    const panelOpen = open ?? guide.autoOpen;
    const hint = item ? PAGE_HINTS[item.href] : undefined;

    return (
        <div className="border-b bg-background px-4 md:px-6">
            {sections.length > 0 && (
                <div
                    className="flex gap-1 overflow-x-auto pt-2"
                    role="tablist"
                    aria-label={`${group.label} sections`}
                >
                    {sections.map((s) => {
                        const first = group.items.find((i) => i.section === s);
                        const on = s === currentSection;

                        return first ? (
                            <Link
                                key={s}
                                href={first.href}
                                className={`shrink-0 rounded-full px-3 py-1.5 text-sm font-semibold ${
                                    on
                                        ? 'bg-primary/10 text-primary'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {s}
                            </Link>
                        ) : null;
                    })}
                </div>
            )}
            <div className="flex items-center gap-2">
                <nav
                    className="flex min-w-0 flex-1 items-center overflow-x-auto"
                    aria-label={group.label}
                >
                    {shown.map((i) => (
                        <Tab key={i.href} item={i} active={i === item} />
                    ))}
                    {more.length > 0 && (
                        <DropdownMenu>
                            <DropdownMenuTrigger className="flex shrink-0 items-center gap-1 px-3 py-3 text-[15px] font-medium text-muted-foreground hover:text-foreground">
                                More <ChevronDown className="size-4" />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start">
                                {more.map((i) => (
                                    <DropdownMenuItem key={i.href} asChild>
                                        <Link href={i.href}>{i.title}</Link>
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </nav>
                {hint?.ai && (
                    <AiMarker
                        ai={hint.ai}
                        onWatch={() => {
                            setStart({ lesson: hint.ai?.lesson });
                            setOpen(true);
                        }}
                        onCall={() => {
                            setStart({ calling: true });
                            setOpen(true);
                        }}
                    />
                )}
                {guide.guide && (
                    <button
                        type="button"
                        onClick={() => {
                            setStart({});
                            setOpen(true);
                        }}
                        aria-label={guide.guide.title}
                        className="my-2 inline-flex shrink-0 items-center gap-2 rounded-full border border-primary/30 bg-primary/5 px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/10"
                    >
                        <span
                            aria-hidden="true"
                            className="grid size-5 place-items-center rounded-full bg-primary text-[11px] text-primary-foreground"
                        >
                            ?
                        </span>
                        <span className="hidden sm:inline">
                            {guide.guide.title}
                        </span>
                    </button>
                )}
            </div>
            {hint?.tip && item && <PageTip id={item.href} text={hint.tip} />}
            {guide.guide && (
                <GuidePanel
                    guide={guide.guide}
                    menuKey={group.key ?? group.label}
                    open={panelOpen}
                    start={start}
                    onOpenChange={(o) => {
                        setOpen(o);
                        if (!o) {
                            guide.markSeen();
                        }
                    }}
                />
            )}
        </div>
    );
}
