import { router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type Reading = {
    status: string;
    pages: number;
    ocr_pages: number;
    type: string | null;
    split_into: number | null;
    parent: boolean;
    sensitive: { ssn: number; bank: number; dob: number; wire: boolean } | null;
    facts: number;
} | null;
export type DealDocument = {
    ulid: string;
    filename: string;
    version: number;
    via: string;
    shared?: boolean;
    reading: Reading;
};
export type Fact = {
    name: string;
    label: string;
    value: string;
    page: number;
    quote: string;
    document: string;
    document_ulid: string;
    applicable: boolean;
};
type Answer = {
    document: string;
    question: string;
    found: boolean;
    answer: string;
    citations: { page: number; quote: string }[];
} | null;

type Compare = {
    document: string;
    ready: boolean;
    message: string | null;
    before: {
        filename: string;
        version: number;
        uploaded: string | null;
    } | null;
    facts: { label: string; before: string; after: string }[];
    added: string[];
    removed: string[];
    more: number;
    scanned: boolean;
} | null;
type Explain = {
    document: string;
    lang: string;
    headline: string;
    points: { text: string; page: number; quote: string; kind: string }[];
    removed: number;
    scanned: string | null;
    disclaimer: string;
} | null;
type Share = { document: string; link: string; until: string } | null;

/** Compare, explain (English or Spanish) and share-by-link for one document (specs/ai/07). */
function DocExtras({ doc }: { doc: DealDocument }) {
    const { docCompare, docExplain, docShare } = usePage<{
        docCompare: Compare;
        docExplain: Explain;
        docShare: Share;
    }>().props;
    const [days, setDays] = useState('3');
    const compare = docCompare?.document === doc.ulid ? docCompare : null;
    const explain = docExplain?.document === doc.ulid ? docExplain : null;
    const share = docShare?.document === doc.ulid ? docShare : null;
    const post = (path: string, data: Record<string, string> = {}) =>
        router.post(`/deals/documents/${doc.ulid}/${path}`, data, {
            preserveScroll: true,
        });

    return (
        <div className="grid gap-1 text-xs">
            <span className="flex flex-wrap items-center gap-3">
                {doc.version > 1 && (
                    <button
                        type="button"
                        className="underline"
                        onClick={() => post('compare')}
                    >
                        What changed from the last version?
                    </button>
                )}
                <button
                    type="button"
                    className="underline"
                    onClick={() => post('explain', { lang: 'en' })}
                >
                    Explain in plain English
                </button>
                <button
                    type="button"
                    className="underline"
                    lang="es"
                    onClick={() => post('explain', { lang: 'es' })}
                >
                    Explicar en español
                </button>
                <span className="flex items-center gap-1">
                    <label htmlFor={`share-${doc.ulid}`}>Safe link for</label>
                    <select
                        id={`share-${doc.ulid}`}
                        className="rounded border px-1 py-0.5"
                        value={days}
                        onChange={(e) => setDays(e.target.value)}
                    >
                        <option value="1">1 day</option>
                        <option value="3">3 days</option>
                        <option value="7">7 days</option>
                    </select>
                    <button
                        type="button"
                        className="underline"
                        title="A link to a copy with Social Security, bank and birth-date numbers blacked out"
                        onClick={() => post('share', { days })}
                    >
                        Make link
                    </button>
                </span>
            </span>
            {share && (
                <p className="rounded border bg-muted/30 p-2">
                    Safe copy link (works until {share.until}):{' '}
                    <input
                        readOnly
                        aria-label="Safe copy link"
                        className="w-full rounded border px-1"
                        value={share.link}
                        onFocus={(e) => e.currentTarget.select()}
                    />
                </p>
            )}
            {compare && (
                <div className="rounded border bg-muted/30 p-2">
                    {compare.before && (
                        <p className="font-medium">
                            Compared with {compare.before.filename} (version{' '}
                            {compare.before.version})
                        </p>
                    )}
                    {compare.message && <p>{compare.message}</p>}
                    {compare.facts.map((f, i) => (
                        <p key={i}>
                            <strong>{f.label}:</strong> {f.before} → {f.after}
                        </p>
                    ))}
                    {compare.added.length > 0 && (
                        <>
                            <p className="mt-1 font-medium">Added</p>
                            {compare.added.map((l, i) => (
                                <p key={i} className="text-green-800">
                                    + {l}
                                </p>
                            ))}
                        </>
                    )}
                    {compare.removed.length > 0 && (
                        <>
                            <p className="mt-1 font-medium">Taken out</p>
                            {compare.removed.map((l, i) => (
                                <p key={i} className="text-red-800">
                                    − {l}
                                </p>
                            ))}
                        </>
                    )}
                    {compare.more > 0 && (
                        <p className="text-muted-foreground">
                            …and {compare.more} more line(s). Open both versions
                            to see everything.
                        </p>
                    )}
                    {compare.scanned && (
                        <p className="text-muted-foreground">
                            One version was scanned, so some words may be
                            misread.
                        </p>
                    )}
                </div>
            )}
            {explain && (
                <div
                    className="rounded border bg-muted/30 p-2 text-sm"
                    lang={explain.lang}
                >
                    {explain.headline && (
                        <p className="font-medium">{explain.headline}</p>
                    )}
                    <ul className="list-disc pl-5">
                        {explain.points.map((p, i) => (
                            <li key={i}>
                                {p.text}{' '}
                                <span className="text-xs text-muted-foreground">
                                    (page {p.page})
                                </span>
                            </li>
                        ))}
                    </ul>
                    {explain.scanned && (
                        <p className="text-xs text-amber-900">
                            {explain.scanned}
                        </p>
                    )}
                    <p className="text-xs text-muted-foreground">
                        {explain.disclaimer}
                    </p>
                </div>
            )}
        </div>
    );
}

/** One document with what DocIQ found: its type, pages, sensitive numbers, safe copy and "ask". */
export function DocRow({
    doc,
    dealUlid,
}: {
    doc: DealDocument;
    dealUlid?: string;
}) {
    const { docAnswer } = usePage<{ docAnswer: Answer }>().props;
    const [asking, setAsking] = useState(docAnswer?.document === doc.ulid);
    const ask = useForm({ question: '' });
    const r = doc.reading;
    const s = r?.sensitive;
    const hidden = s ? s.ssn + s.bank + s.dob : 0;
    const answer = docAnswer?.document === doc.ulid ? docAnswer : null;

    return (
        <div className="grid gap-1">
            <span className="flex flex-wrap items-center gap-2">
                <a
                    className="text-primary underline"
                    href={`/deals/documents/${doc.ulid}`}
                >
                    {doc.filename} (version {doc.version}
                    {doc.via === 'photo' ? ', photo' : ''})
                </a>
                {r?.status === 'queued' && (
                    <Badge variant="outline">Reading…</Badge>
                )}
                {r?.status === 'read' && r.type && (
                    <Badge variant="secondary">{r.type}</Badge>
                )}
                {r?.status === 'read' && r.ocr_pages > 0 && (
                    <span className="text-xs text-muted-foreground">
                        {r.ocr_pages} scanned page(s) read
                    </span>
                )}
                {r?.status === 'failed' && (
                    <Badge variant="outline">Could not be read</Badge>
                )}
                {dealUlid && (
                    <Button
                        size="sm"
                        variant="ghost"
                        title="Clients see shared documents in their deal portal"
                        onClick={() =>
                            router.post(
                                `/deals/${dealUlid}/portal/documents/${doc.ulid}`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {doc.shared
                            ? 'Shared with clients · Stop'
                            : 'Share with clients'}
                    </Button>
                )}
            </span>
            {s?.wire && (
                <p
                    role="alert"
                    className="rounded border border-red-300 bg-red-50 p-2 text-xs text-red-900"
                >
                    This document contains wiring or bank instructions. Never
                    forward it, and confirm any payment details by phone on a
                    number you already know.
                </p>
            )}
            {hidden > 0 && (
                <p className="text-xs text-amber-900">
                    Holds {s?.ssn ? `${s.ssn} Social Security number(s) ` : ''}
                    {s?.bank ? `${s.bank} bank number(s) ` : ''}
                    {s?.dob ? `${s.dob} date(s) of birth ` : ''}—{' '}
                    <a
                        className="underline"
                        href={`/deals/documents/${doc.ulid}/safe`}
                    >
                        download a safe copy to share
                    </a>
                </p>
            )}
            {r?.status === 'read' && r.pages > 0 && (
                <div className="text-xs">
                    {!asking ? (
                        <button
                            type="button"
                            className="underline"
                            onClick={() => setAsking(true)}
                        >
                            Ask this document
                        </button>
                    ) : (
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                ask.post(`/deals/documents/${doc.ulid}/ask`, {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <Input
                                aria-label={`Question about ${doc.filename}`}
                                placeholder="e.g. When does the option period end?"
                                className="w-80"
                                value={ask.data.question}
                                onChange={(e) =>
                                    ask.setData('question', e.target.value)
                                }
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={ask.processing || !ask.data.question}
                            >
                                Ask
                            </Button>
                        </form>
                    )}
                    <div className="mt-1">
                        <DocExtras doc={doc} />
                    </div>
                    {answer && (
                        <div className="mt-1 rounded border bg-muted/30 p-2">
                            <p className="text-muted-foreground">
                                {answer.question}
                            </p>
                            <p>{answer.answer}</p>
                            {answer.citations.map((c, i) => (
                                <p key={i} className="text-muted-foreground">
                                    Page {c.page}: “{c.quote}”
                                </p>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Whole-file upload (DocIQ sorts it into the checklist), documents it could not file, and the facts it found
 * across the deal's documents, each with its page and quote, to apply to the deal.
 */
export function DocIqPanel({
    deal,
    locked,
    unsorted,
    facts,
}: {
    deal: string;
    locked: boolean;
    unsorted: DealDocument[];
    facts: Fact[];
}) {
    const file = useRef<HTMLInputElement>(null);
    const [picked, setPicked] = useState<string[]>([]);
    const visible = unsorted.filter((d) => !d.reading?.split_into);
    const split = unsorted.filter((d) => d.reading?.split_into);

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-semibold">Documents and DocIQ</h2>
                {!locked && (
                    <>
                        <input
                            ref={file}
                            type="file"
                            className="hidden"
                            aria-label="Upload a whole file"
                            accept="application/pdf,image/jpeg,image/png"
                            onChange={(e) => {
                                const f = e.target.files?.[0];
                                if (f)
                                    router.post(
                                        `/deals/${deal}/documents`,
                                        { file: f, via: 'upload' },
                                        {
                                            forceFormData: true,
                                            preserveScroll: true,
                                        },
                                    );
                            }}
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            className="ml-auto"
                            onClick={() => file.current?.click()}
                        >
                            Upload a whole file
                        </Button>
                    </>
                )}
            </div>
            <p className="text-muted-foreground">
                Upload one PDF with everything in it, or a phone photo. DocIQ
                reads it (scans too), splits it into documents and files each
                into the checklist, adds documents the file now needs, and finds
                the key facts.
            </p>
            {split.map((d) => (
                <p key={d.ulid} className="text-xs text-muted-foreground">
                    {d.filename}: split into {d.reading?.split_into} documents
                    and filed.
                </p>
            ))}
            {visible.length > 0 && (
                <div className="grid gap-2">
                    <h3 className="font-medium">Not yet in the checklist</h3>
                    {visible.map((d) => (
                        <DocRow key={d.ulid} doc={d} dealUlid={deal} />
                    ))}
                </div>
            )}
            {facts.length > 0 && (
                <div className="grid gap-1 border-t pt-2">
                    <h3 className="font-medium">Facts found</h3>
                    {facts.map((f) => (
                        <label
                            key={f.name}
                            className="flex flex-wrap items-start gap-2"
                        >
                            <input
                                type="checkbox"
                                className="mt-0.5 size-4"
                                disabled={!f.applicable || locked}
                                checked={picked.includes(f.name)}
                                onChange={(e) =>
                                    setPicked(
                                        e.target.checked
                                            ? [...picked, f.name]
                                            : picked.filter(
                                                  (p) => p !== f.name,
                                              ),
                                    )
                                }
                            />
                            <span className="min-w-36 font-medium">
                                {f.label}
                            </span>
                            <span>{f.value}</span>
                            <span className="text-xs text-muted-foreground">
                                {f.document}, page {f.page}: “{f.quote}”
                            </span>
                        </label>
                    ))}
                    {!locked && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="justify-self-start"
                            disabled={picked.length === 0}
                            onClick={() =>
                                router.post(
                                    `/deals/${deal}/facts`,
                                    { facts: picked },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setPicked([]),
                                    },
                                )
                            }
                        >
                            Apply to the deal and key dates
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}
