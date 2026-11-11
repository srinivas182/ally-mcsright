import { router, usePage } from '@inertiajs/react';
import { SearchIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

import { NavIcon } from '@/components/nav-icon';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Ctrl/⌘ K: jump anywhere. Universal search plugs in here (Platform Core) as modules ship.
 */
export function CommandBar() {
    const { navigation } = usePage().props;
    const [open, setOpen] = useState(false);

    useEffect(() => {
        function onKey(event: KeyboardEvent) {
            if (
                event.key.toLowerCase() === 'k' &&
                (event.metaKey || event.ctrlKey)
            ) {
                event.preventDefault();
                setOpen((value) => !value);
            }
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    return (
        <>
            <Button
                variant="outline"
                size="sm"
                className="w-full justify-start gap-2 text-muted-foreground sm:w-56"
                onClick={() => setOpen(true)}
            >
                <SearchIcon aria-hidden="true" />
                <span>Search or jump to…</span>
                <kbd className="ml-auto hidden rounded border px-1 text-xs sm:inline">
                    Ctrl K
                </kbd>
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="overflow-hidden p-0">
                    <DialogTitle className="sr-only">Search</DialogTitle>
                    <DialogDescription className="sr-only">
                        Type to find a page
                    </DialogDescription>
                    <Command>
                        <CommandInput placeholder="Where do you want to go?" />
                        <CommandList>
                            <CommandEmpty>Nothing found.</CommandEmpty>
                            {navigation.map((group) => (
                                <CommandGroup
                                    key={group.label}
                                    heading={group.label}
                                >
                                    {group.items.map((item) => (
                                        <CommandItem
                                            key={item.href}
                                            onSelect={() => {
                                                setOpen(false);
                                                router.visit(item.href);
                                            }}
                                        >
                                            <NavIcon
                                                name={item.icon}
                                                className="size-4"
                                            />
                                            {item.title}
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            ))}
                        </CommandList>
                    </Command>
                </DialogContent>
            </Dialog>
        </>
    );
}
