import { ChevronRightIcon } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';

export type NetworkNode = {
    ulid: string;
    name: string;
    level: number;
    active: boolean;
    closings: number;
    children: NetworkNode[];
};

/** AG1b: one person in an agent's network tree; open it to see who they brought in. */
export function NetworkBranch({ node }: { node: NetworkNode }) {
    const [open, setOpen] = useState(node.level === 1);
    const leaf = node.children.length === 0;

    return (
        <li>
            <button
                type="button"
                aria-expanded={leaf ? undefined : open}
                className="flex w-full flex-wrap items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-muted/60"
                onClick={() => setOpen(!open)}
                disabled={leaf}
            >
                <ChevronRightIcon
                    aria-hidden="true"
                    className={`size-4 transition-transform ${open ? 'rotate-90' : ''} ${leaf ? 'invisible' : ''}`}
                />
                <span
                    className={
                        node.active
                            ? 'font-medium'
                            : 'text-muted-foreground line-through'
                    }
                >
                    {node.name}
                </span>
                <Badge variant="outline">Level {node.level}</Badge>
                {!node.active && <Badge variant="secondary">Left</Badge>}
                <span className="text-sm text-muted-foreground">
                    {node.closings === 1
                        ? '1 closing recently'
                        : `${node.closings} closings recently`}
                </span>
                {!leaf && (
                    <span className="text-sm text-muted-foreground">
                        · brought in {node.children.length}
                    </span>
                )}
            </button>
            {open && !leaf && (
                <ul className="ml-5 grid gap-0.5 border-l pl-2">
                    {node.children.map((c) => (
                        <NetworkBranch key={c.ulid} node={c} />
                    ))}
                </ul>
            )}
        </li>
    );
}
