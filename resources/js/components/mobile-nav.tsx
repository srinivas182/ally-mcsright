import { Link, usePage } from '@inertiajs/react';
import { MenuIcon } from 'lucide-react';
import { useState } from 'react';

import { NavIcon } from '@/components/nav-icon';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';

/**
 * Phone bottom bar: up to four main destinations plus "More" for everything else.
 */
export function MobileNav() {
    const { navigation } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();
    const [more, setMore] = useState(false);
    const main = navigation
        .flatMap((group) => group.items)
        .filter((item) => item.mobile)
        .slice(0, 4);

    return (
        <>
            <nav
                aria-label="Main"
                className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t bg-background pb-[env(safe-area-inset-bottom)] md:hidden"
            >
                {main.map((item) => (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={
                            isCurrentUrl(item.href) ? 'page' : undefined
                        }
                        className={cn(
                            'flex flex-col items-center gap-1 py-2 text-xs text-muted-foreground',
                            isCurrentUrl(item.href) && 'text-primary',
                        )}
                    >
                        <NavIcon name={item.icon} className="size-5" />
                        <span className="truncate">{item.title}</span>
                    </Link>
                ))}
                <button
                    type="button"
                    onClick={() => setMore(true)}
                    className="col-start-5 flex flex-col items-center gap-1 py-2 text-xs text-muted-foreground"
                >
                    <MenuIcon aria-hidden="true" className="size-5" />
                    <span>More</span>
                </button>
            </nav>
            <Sheet open={more} onOpenChange={setMore}>
                <SheetContent
                    side="bottom"
                    className="max-h-[80vh] overflow-y-auto"
                >
                    <SheetHeader>
                        <SheetTitle>Everything</SheetTitle>
                        <SheetDescription className="sr-only">
                            All pages you can use
                        </SheetDescription>
                    </SheetHeader>
                    <div className="grid gap-4 px-4 pb-6">
                        {navigation.map((group) => (
                            <div key={group.label} className="grid gap-1">
                                <p className="text-xs font-medium text-muted-foreground">
                                    {group.label}
                                </p>
                                {group.items.map((item) => (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        onClick={() => setMore(false)}
                                        className="flex items-center gap-3 rounded-md px-2 py-2 hover:bg-muted"
                                    >
                                        <NavIcon
                                            name={item.icon}
                                            className="size-4"
                                        />
                                        {item.title}
                                    </Link>
                                ))}
                            </div>
                        ))}
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}
