import { Head, router } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { createContext, useContext, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Node = {
    ulid: string;
    name: string;
    active: boolean;
    level: number;
    rank: string | null;
    closings: number;
    recruits: number;
    team: number;
    children: Node[];
};

type Person = { ulid: string; name: string };

type Props = {
    tree: Node[];
    totals: { people: number; sponsored: number; ranks: number };
    canChange: boolean;
    people: Person[];
};

/** Who can be chosen as the person who brought someone in (only for people allowed to change it). */
const Change = createContext<{ can: boolean; people: Person[] }>({
    can: false,
    people: [],
});

function ChangeSponsor({ node, onDone }: { node: Node; onDone: () => void }) {
    const { people } = useContext(Change);
    const [sponsor, setSponsor] = useState('');
    const [reason, setReason] = useState('');

    return (
        <form
            className="grid gap-2 rounded-lg border bg-muted/40 p-3 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(
                    `/compensation/network/${node.ulid}/sponsor`,
                    { sponsor: sponsor || null, reason },
                    { preserveScroll: true, onSuccess: onDone },
                );
            }}
        >
            <p>
                Change who brought in <strong>{node.name}</strong>. It applies
                from today; deals already closed keep the revenue share they
                paid.
            </p>
            <label className="grid gap-1">
                Brought in by
                <select
                    className="h-10 rounded-md border bg-background px-3"
                    value={sponsor}
                    onChange={(e) => setSponsor(e.target.value)}
                >
                    <option value="">Nobody (top of the tree)</option>
                    {people
                        .filter((p) => p.ulid !== node.ulid)
                        .map((p) => (
                            <option key={p.ulid} value={p.ulid}>
                                {p.name}
                            </option>
                        ))}
                </select>
            </label>
            <label className="grid gap-1">
                Why (kept in the audit log)
                <Input
                    value={reason}
                    minLength={10}
                    maxLength={200}
                    required
                    onChange={(e) => setReason(e.target.value)}
                    placeholder="For example: agreed at the team meeting on 3 March"
                />
            </label>
            <div className="flex gap-2">
                <Button
                    type="submit"
                    size="sm"
                    disabled={reason.trim().length < 10}
                >
                    Save change
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onDone}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function matches(node: Node, q: string): boolean {
    return (
        node.name.toLowerCase().includes(q) ||
        node.children.some((c) => matches(c, q))
    );
}

function Branch({
    node,
    query,
    openAll,
}: {
    node: Node;
    query: string;
    openAll: boolean | null;
}) {
    const [open, setOpen] = useState(node.level === 0);
    const [changing, setChanging] = useState(false);
    const change = useContext(Change);
    const hasKids = node.children.length > 0;
    const shown = query !== '' ? true : (openAll ?? open);
    const kids =
        query !== ''
            ? node.children.filter((c) => matches(c, query))
            : node.children;

    return (
        <li className="grid gap-1">
            <div
                className={`flex flex-wrap items-center gap-2 rounded-lg border bg-card px-3 py-2 ${
                    query !== '' && node.name.toLowerCase().includes(query)
                        ? 'border-primary'
                        : ''
                }`}
            >
                <button
                    type="button"
                    aria-expanded={hasKids ? shown : undefined}
                    aria-label={
                        hasKids
                            ? `${shown ? 'Hide' : 'Show'} the people ${node.name} brought in`
                            : undefined
                    }
                    disabled={!hasKids}
                    onClick={() => setOpen(!shown)}
                    className="grid size-7 place-items-center rounded-md hover:bg-muted disabled:invisible"
                >
                    <ChevronRight
                        aria-hidden="true"
                        className={`size-4 transition-transform ${shown ? 'rotate-90' : ''}`}
                    />
                </button>
                <span
                    className={`font-semibold ${node.active ? '' : 'text-muted-foreground line-through'}`}
                >
                    {node.name}
                </span>
                {node.rank && <Badge variant="secondary">{node.rank}</Badge>}
                {!node.active && <Badge variant="outline">Left</Badge>}
                <span className="ml-auto flex flex-wrap gap-x-4 text-sm text-muted-foreground">
                    <span>{node.closings} closed this year</span>
                    <span>
                        {node.recruits} brought in
                        {node.team > node.recruits
                            ? ` · ${node.team} in their team`
                            : ''}
                    </span>
                </span>
                {change.can && node.active && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setChanging(!changing)}
                        aria-expanded={changing}
                    >
                        Change who brought them in
                    </Button>
                )}
            </div>
            {changing && (
                <ChangeSponsor node={node} onDone={() => setChanging(false)} />
            )}
            {shown && kids.length > 0 && (
                <ul className="ml-5 grid gap-1 border-l pl-4">
                    {kids.map((c) => (
                        <Branch
                            key={c.ulid}
                            node={c}
                            query={query}
                            openAll={openAll}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

/**
 * CM1: the brokerage's network tree (genealogy): everyone who joined without a sponsor at the top, and the people
 * each agent brought in below them, with rank, production and team size.
 */
export default function NetworkTree({
    tree,
    totals,
    canChange,
    people,
}: Props) {
    const [q, setQ] = useState('');
    const [openAll, setOpenAll] = useState<boolean | null>(null);
    const query = q.trim().toLowerCase();
    const top = useMemo(
        () => (query === '' ? tree : tree.filter((n) => matches(n, query))),
        [tree, query],
    );

    return (
        <Change.Provider value={{ can: canChange, people }}>
            <Head title="Network tree" />
            <div className="flex max-w-5xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Network tree"
                    description="Who brought in whom. Revenue share follows this tree, level by level."
                />
                <section className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border bg-card p-4">
                        <p className="text-sm text-muted-foreground">People</p>
                        <p className="text-2xl font-semibold">
                            {totals.people}
                        </p>
                    </div>
                    <div className="rounded-xl border bg-card p-4">
                        <p className="text-sm text-muted-foreground">
                            Brought in by another agent
                        </p>
                        <p className="text-2xl font-semibold">
                            {totals.sponsored}
                        </p>
                    </div>
                    <div className="rounded-xl border bg-card p-4">
                        <p className="text-sm text-muted-foreground">Ranks</p>
                        <p className="text-2xl font-semibold">
                            {totals.ranks > 0 ? totals.ranks : 'Not set up'}
                        </p>
                    </div>
                </section>
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        aria-label="Find a person"
                        placeholder="Find a person"
                        className="h-10 max-w-xs"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                    />
                    <Button variant="outline" onClick={() => setOpenAll(true)}>
                        Open all
                    </Button>
                    <Button variant="ghost" onClick={() => setOpenAll(false)}>
                        Close all
                    </Button>
                </div>
                {top.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-muted-foreground">
                        {query
                            ? 'Nobody by that name.'
                            : "Nobody here yet. Agents appear as they join. When an agent accepts another agent's invitation, they are placed under that agent."}
                    </p>
                ) : (
                    <ul className="grid gap-1">
                        {top.map((n) => (
                            <Branch
                                key={n.ulid}
                                node={n}
                                query={query}
                                openAll={openAll}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </Change.Provider>
    );
}

NetworkTree.layout = {
    breadcrumbs: [{ title: 'Network tree', href: '/compensation/network' }],
};
