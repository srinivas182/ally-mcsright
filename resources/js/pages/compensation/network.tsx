import { Head, router } from '@inertiajs/react';
import { Info, Minus, Plus } from 'lucide-react';
import { createContext, useContext, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

type Node = {
    ulid: string;
    name: string;
    active: boolean;
    level: number;
    rank: string | null;
    closings: number;
    recruits: number;
    team: number;
    sponsor: string | null;
    co_sponsor: { name: string | null; share: string } | null;
    joined: string | null;
    kind: string;
    children: Node[];
};

type Person = { ulid: string; name: string };

type Props = {
    tree: Node[];
    brokerage: string;
    totals: { people: number; sponsored: number; ranks: number };
    canChange: boolean;
    people: Person[];
};

/** Who can be chosen as the person who brought someone in (only for people allowed to change it). */
const Change = createContext<{ can: boolean; people: Person[] }>({
    can: false,
    people: [],
});

const COLORS = [
    'bg-sky-100 text-sky-800',
    'bg-emerald-100 text-emerald-800',
    'bg-amber-100 text-amber-800',
    'bg-rose-100 text-rose-800',
    'bg-violet-100 text-violet-800',
    'bg-teal-100 text-teal-800',
];

function Avatar({ name }: { name: string }) {
    const initials = name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase())
        .join('');
    const color =
        COLORS[
            Array.from(name).reduce((s, c) => s + c.charCodeAt(0), 0) %
                COLORS.length
        ];

    return (
        <span
            aria-hidden="true"
            className={`grid size-11 shrink-0 place-items-center rounded-full text-sm font-bold ${color}`}
        >
            {initials}
        </span>
    );
}

function matches(node: Node, q: string): boolean {
    return (
        node.name.toLowerCase().includes(q) ||
        node.children.some((c) => matches(c, q))
    );
}

function ChangeSponsor({ node, onDone }: { node: Node; onDone: () => void }) {
    const { people } = useContext(Change);
    const [sponsor, setSponsor] = useState('');
    const [co, setCo] = useState('');
    const [share, setShare] = useState('50');
    const [reason, setReason] = useState('');
    const others = people.filter((p) => p.ulid !== node.ulid);

    return (
        <form
            className="ml-14 grid gap-3 rounded-lg border bg-muted/40 p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(
                    `/compensation/network/${node.ulid}/sponsor`,
                    {
                        sponsor: sponsor || null,
                        co_sponsor: co || null,
                        co_share: co ? share : null,
                        reason,
                    },
                    { preserveScroll: true, onSuccess: onDone },
                );
            }}
        >
            <p>
                Change who brought in <strong>{node.name}</strong>. It applies
                from today; deals already closed keep the revenue share they
                paid.
            </p>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="grid gap-1">
                    Sponsor (brought them in)
                    <select
                        className="h-10 rounded-md border bg-background px-3"
                        value={sponsor}
                        onChange={(e) => setSponsor(e.target.value)}
                    >
                        <option value="">Nobody (top of the tree)</option>
                        {others.map((p) => (
                            <option key={p.ulid} value={p.ulid}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1">
                    Co-sponsor (optional)
                    <select
                        className="h-10 rounded-md border bg-background px-3"
                        value={co}
                        disabled={!sponsor}
                        onChange={(e) => setCo(e.target.value)}
                    >
                        <option value="">No co-sponsor</option>
                        {others
                            .filter((p) => p.ulid !== sponsor)
                            .map((p) => (
                                <option key={p.ulid} value={p.ulid}>
                                    {p.name}
                                </option>
                            ))}
                    </select>
                </label>
            </div>
            {co && (
                <label className="flex flex-wrap items-center gap-2">
                    The co-sponsor gets
                    <Input
                        type="number"
                        min={1}
                        max={99}
                        className="h-9 w-20"
                        value={share}
                        onChange={(e) => setShare(e.target.value)}
                        aria-label="Co-sponsor share of level 1 (%)"
                    />
                    % of level 1; the sponsor gets the rest. Deeper levels
                    follow the sponsor.
                </label>
            )}
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

function Details({ node }: { node: Node }) {
    return (
        <Popover>
            <PopoverTrigger
                aria-label={`About ${node.name}`}
                className="grid size-8 place-items-center rounded-full bg-muted text-muted-foreground hover:bg-muted/70 hover:text-foreground"
            >
                <Info className="size-4" aria-hidden="true" />
            </PopoverTrigger>
            <PopoverContent align="end" className="w-72 text-sm">
                <p className="mb-2 text-base font-semibold">{node.name}</p>
                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                    <dt className="text-muted-foreground">Rank</dt>
                    <dd>{node.rank ?? '—'}</dd>
                    <dt className="text-muted-foreground">Closed this year</dt>
                    <dd>{node.closings}</dd>
                    <dt className="text-muted-foreground">Brought in</dt>
                    <dd>{node.recruits}</dd>
                    <dt className="text-muted-foreground">Whole team</dt>
                    <dd>{node.team}</dd>
                    <dt className="text-muted-foreground">Sponsor</dt>
                    <dd>{node.sponsor ?? 'None (top)'}</dd>
                    {node.co_sponsor && (
                        <>
                            <dt className="text-muted-foreground">
                                Co-sponsor
                            </dt>
                            <dd>
                                {node.co_sponsor.name} ({node.co_sponsor.share}%
                                of level 1)
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">Joined</dt>
                    <dd>
                        {node.joined
                            ? new Date(
                                  `${node.joined}T00:00:00`,
                              ).toLocaleDateString()
                            : '—'}
                    </dd>
                    <dt className="text-muted-foreground">Status</dt>
                    <dd>{node.active ? 'Active' : 'Left'}</dd>
                </dl>
            </PopoverContent>
        </Popover>
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
    const [open, setOpen] = useState(node.level <= 1);
    const [changing, setChanging] = useState(false);
    const change = useContext(Change);
    const hasKids = node.children.length > 0;
    const shown = query !== '' ? true : (openAll ?? open);
    const kids =
        query !== ''
            ? node.children.filter((c) => matches(c, query))
            : node.children;
    const hit = query !== '' && node.name.toLowerCase().includes(query);

    return (
        <li className="relative grid gap-2 pl-6">
            {/* The line from the parent's column to this card. */}
            <span
                aria-hidden="true"
                className="absolute top-8 left-0 w-6 border-t-2 border-dashed border-border"
            />
            <div className="relative flex items-center gap-3 rounded-xl border bg-card px-4 py-3 shadow-xs">
                <button
                    type="button"
                    aria-expanded={hasKids ? shown : undefined}
                    aria-label={
                        hasKids
                            ? `${shown ? 'Hide' : 'Show'} the people ${node.name} brought in`
                            : `${node.name} has not brought anyone in yet`
                    }
                    disabled={!hasKids}
                    onClick={() => setOpen(!shown)}
                    className="absolute top-1/2 -left-3.5 grid size-7 -translate-y-1/2 place-items-center rounded-full bg-foreground text-background disabled:bg-muted disabled:text-muted-foreground"
                >
                    {shown && hasKids ? (
                        <Minus className="size-4" aria-hidden="true" />
                    ) : (
                        <Plus className="size-4" aria-hidden="true" />
                    )}
                </button>
                <Avatar name={node.name} />
                <div className="min-w-0 flex-1">
                    <p
                        className={`truncate font-semibold ${hit ? 'text-primary' : ''} ${node.active ? '' : 'text-muted-foreground line-through'}`}
                    >
                        {node.name}
                    </p>
                    <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                        {node.rank && (
                            <Badge variant="secondary">{node.rank}</Badge>
                        )}
                        <span>{node.closings} closed this year</span>
                        <span>
                            {node.recruits} brought in
                            {node.team > node.recruits
                                ? ` · ${node.team} in team`
                                : ''}
                        </span>
                        {node.co_sponsor && (
                            <span>Co-sponsored by {node.co_sponsor.name}</span>
                        )}
                    </p>
                </div>
                {change.can && node.active && (
                    <Button
                        size="sm"
                        variant="ghost"
                        className="hidden md:inline-flex"
                        onClick={() => setChanging(!changing)}
                        aria-expanded={changing}
                    >
                        Change sponsor
                    </Button>
                )}
                <span className="grid size-12 shrink-0 place-items-center rounded-full bg-muted text-center text-xs leading-tight font-semibold">
                    Level
                    <br />
                    {node.level}
                </span>
                <Details node={node} />
            </div>
            {changing && (
                <ChangeSponsor node={node} onDone={() => setChanging(false)} />
            )}
            {shown && kids.length > 0 && (
                <ul className="ml-6 grid gap-2 border-l-2 border-dashed border-border pl-2">
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
 * CM1/CM3: the brokerage's network tree (genealogy): the brokerage at the root, everyone who joined without a
 * sponsor at level 1, and the people each agent brought in below them, with rank, production, team size, sponsor
 * and co-sponsor. Money managers can change a sponsor or add a co-sponsor.
 */
export default function NetworkTree({
    tree,
    brokerage,
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
                            : "Nobody here yet. Agents appear as they join. When an agent joins through another agent's link, they are placed under that agent."}
                    </p>
                ) : (
                    <div className="grid gap-2">
                        <div className="flex items-center gap-3 rounded-xl border bg-card px-4 py-3 shadow-xs">
                            <Avatar name={brokerage} />
                            <div className="flex-1">
                                <p className="font-semibold text-primary">
                                    {brokerage}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    The brokerage (top of the tree)
                                </p>
                            </div>
                            <span className="grid size-12 place-items-center rounded-full bg-muted text-center text-xs leading-tight font-semibold">
                                Level
                                <br />0
                            </span>
                        </div>
                        <ul className="ml-6 grid gap-2 border-l-2 border-dashed border-border pl-2">
                            {top.map((n) => (
                                <Branch
                                    key={n.ulid}
                                    node={n}
                                    query={query}
                                    openAll={openAll}
                                />
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </Change.Provider>
    );
}

NetworkTree.layout = {
    breadcrumbs: [{ title: 'Network tree', href: '/compensation/network' }],
};
