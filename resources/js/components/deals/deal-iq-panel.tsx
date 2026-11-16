import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { VoiceButton } from '@/components/crm/voice-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { postJson } from '@/lib/http';

type Action = {
    type: string;
    params: Record<string, string | number | boolean | null>;
    says: string;
    problem: string | null;
};
type Result = { says: string; done: boolean; note: string; link?: string };
export type DealIqProps = {
    property_type: string | null;
    financing: string | null;
    propertyTypes: Record<string, string>;
    financingTypes: Record<string, string>;
    uploadRequests: {
        what: string;
        name: string;
        role: string;
        status: string;
        sent_at: string;
    }[];
    history: {
        said: string;
        answer: string | null;
        status: string;
        results: Result[];
        at: string;
    }[];
    roles: string[];
    setup: boolean;
};

const select = 'rounded border bg-background px-2 py-1';

/**
 * Deal-IQ on a deal (specs/ai/02, DQ1–DQ2): talk or type, preview then confirm;
 * the home and the loan (the checklist adapts); asking the other side for documents.
 */
export function DealIqPanel({
    dealUlid,
    locked,
    iq,
    parties,
}: {
    dealUlid: string;
    locked: boolean;
    iq: DealIqProps;
    parties: { role: string; name: string; email: string | null }[];
}) {
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [turn, setTurn] = useState<{
        ulid: string;
        answer: string;
        actions: Action[];
    } | null>(null);
    const [chosen, setChosen] = useState<number[]>([]);
    const [results, setResults] = useState<Result[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [req, setReq] = useState({
        what: '',
        role: 'lender',
        name: '',
        email: '',
        note: '',
    });
    const base = `/deals/${dealUlid}`;

    const ask = () => {
        setBusy(true);
        setResults(null);
        void postJson<{ ulid: string; answer: string; actions: Action[] }>(
            `${base}/assistant`,
            { text },
        )
            .then((t) => {
                setTurn(t);
                setChosen(
                    t.actions
                        .map((a, i) => (a.problem ? -1 : i))
                        .filter((i) => i >= 0),
                );
                setError(null);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setBusy(false));
    };
    const confirm = () => {
        if (!turn) {
            return;
        }
        setBusy(true);
        void postJson<{ results: Result[] }>(`${base}/assistant/${turn.ulid}`, {
            chosen,
        })
            .then((r) => {
                setResults(r.results);
                setTurn(null);
                setText('');
                router.reload({
                    only: ['dates', 'items', 'missing', 'dealIq'],
                });
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setBusy(false));
    };
    const pickParty = (role: string) => {
        const p = parties.find((x) => x.role === role);
        setReq({
            ...req,
            role,
            name: p?.name ?? req.name,
            email: p?.email ?? req.email,
        });
    };

    return (
        <section
            className="grid gap-3 rounded-lg border p-4 text-sm"
            aria-label="Deal-IQ"
        >
            <p className="font-medium">Deal-IQ</p>
            {iq.setup && (
                <p className="rounded-md border border-amber-500 p-2">
                    This deal still needs setting up.{' '}
                    <Link href={`${base}/setup`} className="underline">
                        Check the details
                    </Link>
                </p>
            )}
            {!locked && (
                <div className="grid gap-2">
                    <span className="flex flex-wrap gap-2">
                        <Input
                            aria-label="Ask or tell Deal-IQ"
                            className="min-w-56 flex-1"
                            placeholder='e.g. "Move the inspection deadline to Friday and tell the lender"'
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            onKeyDown={(e) =>
                                e.key === 'Enter' && text.trim() !== '' && ask()
                            }
                        />
                        <VoiceButton
                            onText={(t) => setText(t)}
                            label="Speak to Deal-IQ"
                        />
                        <Button
                            disabled={busy || text.trim() === ''}
                            onClick={ask}
                        >
                            Go
                        </Button>
                    </span>
                    {error && (
                        <p role="alert" className="text-destructive">
                            {error}
                        </p>
                    )}
                    {turn && (
                        <div className="grid gap-2 rounded-md bg-muted/40 p-3">
                            {turn.answer && <p>{turn.answer}</p>}
                            {turn.actions.length > 0 && (
                                <>
                                    <p className="font-medium">
                                        Deal-IQ will, once you confirm:
                                    </p>
                                    {turn.actions.map((a, i) => (
                                        <label
                                            key={i}
                                            className="flex items-start gap-2"
                                        >
                                            <input
                                                type="checkbox"
                                                className="mt-0.5 size-4"
                                                disabled={a.problem !== null}
                                                checked={chosen.includes(i)}
                                                onChange={(e) =>
                                                    setChosen(
                                                        e.target.checked
                                                            ? [...chosen, i]
                                                            : chosen.filter(
                                                                  (x) =>
                                                                      x !== i,
                                                              ),
                                                    )
                                                }
                                            />
                                            <span>
                                                {a.says}
                                                {a.problem && (
                                                    <span className="block text-destructive">
                                                        {a.problem}
                                                    </span>
                                                )}
                                            </span>
                                        </label>
                                    ))}
                                    <span className="flex gap-2">
                                        <Button
                                            size="sm"
                                            disabled={
                                                busy || chosen.length === 0
                                            }
                                            onClick={confirm}
                                        >
                                            Confirm
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                void postJson(
                                                    `${base}/assistant/${turn.ulid}/dismiss`,
                                                    {},
                                                );
                                                setTurn(null);
                                            }}
                                        >
                                            Cancel
                                        </Button>
                                    </span>
                                </>
                            )}
                        </div>
                    )}
                    {results && (
                        <ul className="grid gap-1">
                            {results.map((r, i) => (
                                <li
                                    key={i}
                                    className={r.done ? '' : 'text-destructive'}
                                >
                                    {r.done ? '✓' : '✗'} {r.note}{' '}
                                    {r.link && (
                                        <Link
                                            href={r.link}
                                            className="underline"
                                        >
                                            Open
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground">
                    The home and the loan:
                </span>
                <select
                    aria-label="Property type"
                    className={select}
                    value={iq.property_type ?? ''}
                    disabled={locked}
                    onChange={(e) =>
                        router.post(
                            `${base}/property`,
                            {
                                property_type: e.target.value || null,
                                financing: iq.financing,
                            },
                            { preserveScroll: true },
                        )
                    }
                >
                    <option value="">Type of home…</option>
                    {Object.entries(iq.propertyTypes).map(([k, l]) => (
                        <option key={k} value={k}>
                            {l}
                        </option>
                    ))}
                </select>
                <select
                    aria-label="Financing"
                    className={select}
                    value={iq.financing ?? ''}
                    disabled={locked}
                    onChange={(e) =>
                        router.post(
                            `${base}/property`,
                            {
                                property_type: iq.property_type,
                                financing: e.target.value || null,
                            },
                            { preserveScroll: true },
                        )
                    }
                >
                    <option value="">Financing…</option>
                    {Object.entries(iq.financingTypes).map(([k, l]) => (
                        <option key={k} value={k}>
                            {l}
                        </option>
                    ))}
                </select>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        router.post(
                            `${base}/timeline`,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Rebuild the timeline
                </Button>
            </div>
            {!locked && (
                <details>
                    <summary className="cursor-pointer">
                        Ask someone on the deal to upload a document
                    </summary>
                    <div className="mt-2 grid gap-2 md:grid-cols-2">
                        <Input
                            aria-label="What you need"
                            placeholder="What you need, e.g. Loan approval letter"
                            value={req.what}
                            onChange={(e) =>
                                setReq({ ...req, what: e.target.value })
                            }
                        />
                        <select
                            aria-label="Who from"
                            className={select}
                            value={req.role}
                            onChange={(e) => pickParty(e.target.value)}
                        >
                            {iq.roles.map((r) => (
                                <option key={r} value={r}>
                                    {r.replace('_', '-')}
                                </option>
                            ))}
                        </select>
                        <Input
                            aria-label="Their name"
                            placeholder="Their name"
                            value={req.name}
                            onChange={(e) =>
                                setReq({ ...req, name: e.target.value })
                            }
                        />
                        <Input
                            aria-label="Their email"
                            placeholder="Their email"
                            type="email"
                            value={req.email}
                            onChange={(e) =>
                                setReq({ ...req, email: e.target.value })
                            }
                        />
                        <Input
                            aria-label="Note (optional)"
                            placeholder="Note (optional)"
                            className="md:col-span-2"
                            value={req.note}
                            onChange={(e) =>
                                setReq({ ...req, note: e.target.value })
                            }
                        />
                        <Button
                            size="sm"
                            className="justify-self-start"
                            disabled={
                                req.what.trim() === '' ||
                                req.email.trim() === ''
                            }
                            onClick={() =>
                                router.post(`${base}/upload-requests`, req, {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        setReq({ ...req, what: '', note: '' }),
                                })
                            }
                        >
                            Send a secure upload link
                        </Button>
                    </div>
                </details>
            )}
            {iq.uploadRequests.length > 0 && (
                <ul className="grid gap-1">
                    {iq.uploadRequests.map((r, i) => (
                        <li
                            key={i}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <Badge
                                variant={
                                    r.status === 'received'
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {r.status}
                            </Badge>
                            {r.what} from {r.name} ({r.role.replace('_', '-')}),
                            sent {r.sent_at}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
