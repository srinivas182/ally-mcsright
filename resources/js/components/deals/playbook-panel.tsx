import { Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

export type PlaybookProps = {
    key: string;
    name: string;
    choices: Record<string, string>;
    steps: {
        step: string;
        subject: string;
        to: string[];
        due: string;
        status: string;
        auto: boolean;
        recipients: number;
    }[];
    mls: {
        expected: string;
        seen: string | null;
        due_on: string;
        overdue: boolean;
    }[];
    linked: { ulid: string; street: string } | null;
    linkable: { ulid: string; street: string }[];
};

const select = 'rounded border bg-background px-2 py-1';

/** The deal's playbook, its emails, the MLS status guard and 1031 links (specs/ai/02, DQ5a). */
export function PlaybookPanel({
    dealUlid,
    locked,
    playbook: p,
}: {
    dealUlid: string;
    locked: boolean;
    playbook: PlaybookProps;
}) {
    const base = `/deals/${dealUlid}`;
    const opts = { preserveScroll: true };

    return (
        <section
            className="grid gap-3 rounded-lg border p-4 text-sm"
            aria-label="Playbook"
        >
            {p.mls.map((m) => (
                <p
                    key={m.expected}
                    role="alert"
                    className={`rounded-md border p-2 ${m.overdue ? 'border-destructive text-destructive' : 'border-amber-500'}`}
                >
                    Update the MLS: it shows{' '}
                    <strong>{m.seen ?? 'nothing'}</strong>, it should show{' '}
                    <strong>{m.expected}</strong>
                    {m.overdue
                        ? ` (overdue since ${m.due_on}; your broker has been told)`
                        : ` by ${m.due_on}`}
                    .
                </p>
            ))}
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">Playbook</span>
                <select
                    aria-label="Playbook"
                    className={select}
                    value={p.key}
                    disabled={locked}
                    onChange={(e) =>
                        router.post(
                            `${base}/playbook`,
                            { playbook: e.target.value },
                            opts,
                        )
                    }
                >
                    {Object.entries(p.choices).map(([k, l]) => (
                        <option key={k} value={k}>
                            {l}
                        </option>
                    ))}
                </select>
                <Link
                    href="/deals/playbooks"
                    className="text-muted-foreground underline"
                >
                    What each playbook adds
                </Link>
            </div>
            {(p.key === 'exchange_1031' || p.linked) && (
                <div className="flex flex-wrap items-center gap-2">
                    <span>
                        1031 exchange: the{' '}
                        {p.key === 'exchange_1031'
                            ? 'replacement purchase'
                            : 'linked sale'}
                    </span>
                    <select
                        aria-label="Linked deal"
                        className={select}
                        value={p.linked?.ulid ?? ''}
                        disabled={locked}
                        onChange={(e) =>
                            router.post(
                                `${base}/link`,
                                { deal: e.target.value || null },
                                opts,
                            )
                        }
                    >
                        <option value="">Not linked</option>
                        {p.linked &&
                            !p.linkable.some(
                                (d) => d.ulid === p.linked?.ulid,
                            ) && (
                                <option value={p.linked.ulid}>
                                    {p.linked.street}
                                </option>
                            )}
                        {p.linkable.map((d) => (
                            <option key={d.ulid} value={d.ulid}>
                                {d.street}
                            </option>
                        ))}
                    </select>
                    <span className="text-muted-foreground">
                        Both deals show the 45- and 180-day deadlines.
                    </span>
                </div>
            )}
            {p.steps.length > 0 && (
                <ul className="grid gap-1">
                    {p.steps.map((s) => (
                        <li
                            key={s.step}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <Badge
                                variant={
                                    s.status === 'ready'
                                        ? 'default'
                                        : s.status === 'sent'
                                          ? 'secondary'
                                          : 'outline'
                                }
                            >
                                {s.status}
                            </Badge>
                            <span>
                                {s.subject} →{' '}
                                {s.to
                                    .map((r) => r.replace('_', '-'))
                                    .join(', ')}{' '}
                                ({s.recipients} with an email)
                                {s.status === 'waiting' && (
                                    <span className="text-muted-foreground">
                                        , due {s.due}
                                        {s.auto ? ', sends by itself' : ''}
                                    </span>
                                )}
                            </span>
                            {!locked &&
                                ['ready', 'waiting'].includes(s.status) && (
                                    <>
                                        <Button
                                            size="sm"
                                            variant={
                                                s.status === 'ready'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            disabled={s.recipients === 0}
                                            onClick={() =>
                                                router.post(
                                                    `${base}/playbook/${s.step}/send`,
                                                    {},
                                                    opts,
                                                )
                                            }
                                        >
                                            Send now
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    `${base}/playbook/${s.step}/skip`,
                                                    {},
                                                    opts,
                                                )
                                            }
                                        >
                                            Skip
                                        </Button>
                                    </>
                                )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
