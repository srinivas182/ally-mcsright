import { router, usePage } from '@inertiajs/react';
import { SearchIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

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
import { shellWords } from '@/lib/shell-words';

type Group = {
    key: string;
    label: string;
    items: { title: string; subtitle: string | null; href: string }[];
};

/**
 * Ctrl/⌘ K, or the search button: universal search (PW1, specs/core/17). Pages from the menu, plus contacts, deals,
 * listings and people the person may see, found on the server as they type.
 */
export function CommandBar() {
    const { navigation, simple } = usePage().props;
    const w = shellWords(simple?.locale);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [groups, setGroups] = useState<Group[]>([]);
    const [loading, setLoading] = useState(false);

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

    useEffect(() => {
        const q = query.trim();
        if (!open || q.length < 2) {
            setGroups([]);
            setLoading(false);
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        const timer = window.setTimeout(() => {
            fetch(`/search?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : { groups: [] }))
                .then((data: { groups: Group[] }) =>
                    setGroups(data.groups ?? []),
                )
                .catch(() => undefined)
                .finally(() => setLoading(false));
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, open]);

    const pages = useMemo(() => {
        const q = query.trim().toLowerCase();
        return navigation
            .map((group) => ({
                ...group,
                items: group.items.filter(
                    (item) => q === '' || item.title.toLowerCase().includes(q),
                ),
            }))
            .filter((group) => group.items.length > 0);
    }, [navigation, query]);

    const go = (href: string) => {
        setOpen(false);
        setQuery('');
        router.visit(href);
    };

    return (
        <>
            <Button
                variant="outline"
                size="sm"
                className="w-full justify-start gap-2 text-muted-foreground sm:w-56"
                onClick={() => setOpen(true)}
            >
                <SearchIcon aria-hidden="true" />
                <span>{w.searchButton}</span>
                <kbd className="ml-auto hidden rounded border px-1 text-xs sm:inline">
                    Ctrl K
                </kbd>
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="overflow-hidden p-0">
                    <DialogTitle className="sr-only">
                        {w.searchTitle}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {w.searchHint}
                    </DialogDescription>
                    <Command shouldFilter={false}>
                        <CommandInput
                            placeholder={w.searchPlaceholder}
                            value={query}
                            onValueChange={setQuery}
                            className="text-base"
                        />
                        <CommandList>
                            {loading && groups.length === 0 && (
                                <p
                                    role="status"
                                    className="p-3 text-sm text-muted-foreground"
                                >
                                    {w.searching}
                                </p>
                            )}
                            {!loading &&
                                pages.length === 0 &&
                                groups.length === 0 && (
                                    <CommandEmpty>
                                        {query.trim().length < 2
                                            ? w.typeMore
                                            : w.nothing}
                                    </CommandEmpty>
                                )}
                            {groups.map((group) => (
                                <CommandGroup
                                    key={group.key}
                                    heading={group.label}
                                >
                                    {group.items.map((item) => (
                                        <CommandItem
                                            key={item.href}
                                            value={item.href}
                                            onSelect={() => go(item.href)}
                                        >
                                            <span className="grid">
                                                <span>{item.title}</span>
                                                {item.subtitle && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {item.subtitle}
                                                    </span>
                                                )}
                                            </span>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            ))}
                            {pages.map((group) => (
                                <CommandGroup
                                    key={group.label}
                                    heading={
                                        groups.length > 0
                                            ? `${w.pages} · ${group.label}`
                                            : group.label
                                    }
                                >
                                    {group.items.map((item) => (
                                        <CommandItem
                                            key={item.href}
                                            value={item.href}
                                            onSelect={() => go(item.href)}
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
