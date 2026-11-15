import { Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';

export type LiveChat = {
    ulid: string;
    page: string;
    outcome: string;
    score: number | null;
    taken_by: string | null;
    last: string;
};

/** Website chats happening now (the visitor wrote in the last ten minutes), to join from the back office. */
export function LiveChats({ live }: { live: LiveChat[] }) {
    if (live.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No one is chatting right now. Hot-lead alerts link straight to
                the chat, so you can join while the visitor is still there.
            </p>
        );
    }

    return (
        <ul className="grid gap-2 text-sm">
            {live.map((c) => (
                <li key={c.ulid}>
                    <Link
                        href={`/ailly/chats/${c.ulid}`}
                        className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3 hover:bg-muted/40"
                    >
                        <span className="flex items-center gap-2">
                            <span
                                className="size-2 rounded-full bg-green-600"
                                aria-hidden="true"
                            />
                            <span>{c.page || '/'}</span>
                        </span>
                        <span className="flex items-center gap-2">
                            {c.score !== null && (
                                <span className="text-muted-foreground">
                                    Lead score {c.score}
                                </span>
                            )}
                            {c.taken_by ? (
                                <Badge>{c.taken_by} is in the chat</Badge>
                            ) : (
                                <Badge variant="outline">Join</Badge>
                            )}
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
