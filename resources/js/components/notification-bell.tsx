import { Link, router, usePage } from '@inertiajs/react';
import { BellIcon } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { getJson, postJson } from '@/lib/http';

export type Notice = {
    ulid: string;
    type: string;
    label: string;
    title: string;
    body: string | null;
    url: string | null;
    urgent: boolean;
    read: boolean;
    at: string;
};

/** The bell (specs/core/10, N1): unread count, the latest alerts, and a way to the full inbox. */
export function NotificationBell() {
    const shared = usePage().props.notices as { unread: number } | null;
    const [unread, setUnread] = useState<number | null>(null);
    const [items, setItems] = useState<Notice[] | null>(null);
    const count = unread ?? shared?.unread ?? 0;

    if (!shared) {
        return null;
    }

    const load = () => {
        void getJson<{ notices: Notice[]; unread: number }>(
            '/notifications/latest',
        )
            .then((r) => {
                setItems(r.notices);
                setUnread(r.unread);
            })
            .catch(() => setItems([]));
    };

    const open = (n: Notice) => {
        void postJson<{ unread: number }>(`/notifications/read/${n.ulid}`, {})
            .then((r) => setUnread(r.unread))
            .catch(() => undefined);
        if (n.url) {
            router.visit(n.url);
        }
    };

    return (
        <Popover onOpenChange={(o) => o && load()}>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative"
                    aria-label={
                        count > 0
                            ? `Notifications, ${count} unread`
                            : 'Notifications'
                    }
                >
                    <BellIcon />
                    {count > 0 && (
                        <span className="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-primary px-1 text-[10px] leading-4 text-primary-foreground">
                            {count > 99 ? '99+' : count}
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="grid w-80 gap-2 p-2">
                <div className="flex items-center justify-between px-1">
                    <span className="text-sm font-medium">Notifications</span>
                    {count > 0 && (
                        <Button
                            variant="link"
                            size="sm"
                            className="h-auto p-0"
                            onClick={() =>
                                void postJson<{ unread: number }>(
                                    '/notifications/read',
                                    {},
                                ).then((r) => {
                                    setUnread(r.unread);
                                    load();
                                })
                            }
                        >
                            Mark all read
                        </Button>
                    )}
                </div>
                {items === null ? (
                    <p className="px-1 text-sm text-muted-foreground">
                        Loading…
                    </p>
                ) : items.length === 0 ? (
                    <p className="px-1 text-sm text-muted-foreground">
                        You're all caught up.
                    </p>
                ) : (
                    <ul className="grid gap-1">
                        {items.map((n) => (
                            <li key={n.ulid}>
                                <button
                                    type="button"
                                    onClick={() => open(n)}
                                    className="grid w-full gap-0.5 rounded-md p-2 text-left text-sm hover:bg-muted"
                                >
                                    <span
                                        className={n.read ? '' : 'font-medium'}
                                    >
                                        {n.urgent && (
                                            <span className="mr-1 text-destructive">
                                                Urgent:
                                            </span>
                                        )}
                                        {n.title}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {n.label} ·{' '}
                                        {new Date(n.at).toLocaleString()}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <Link href="/notifications" className="px-1 text-sm underline">
                    See all and choose what you get
                </Link>
            </PopoverContent>
        </Popover>
    );
}
