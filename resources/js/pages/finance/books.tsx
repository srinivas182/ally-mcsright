import { Head, Link, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Ours = { code: string; name: string; type: string };
type Theirs = { id: string; name: string; type: string };
type Books = {
    ulid: string;
    app: 'quickbooks' | 'xero';
    name: string;
    org: string | null;
    post_from: string;
    map: Record<string, string>;
    waiting: {
        reason: 'map' | 'refused';
        accounts?: string[];
        message?: string;
    } | null;
    accounts: Theirs[];
    error: string | null;
};

/**
 * Finance → Books (specs/connect/13): RightAlly's journals posted to QuickBooks Online or Xero. Each RightAlly
 * account is mapped once to an account in the books; agent and deal sub-accounts post to their parent's account.
 */
export default function BooksPage({
    ours,
    books,
    can,
}: {
    ours: Ours[];
    books: Books[];
    can: { manage: boolean };
}) {
    return (
        <>
            <Head title="Books" />
            <div className="grid max-w-4xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Books"
                    description="Every RightAlly journal (commissions, fees, revenue share, payouts) posted to your accounting app automatically."
                />
                {books.length === 0 && (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <p>Connect QuickBooks Online or Xero first.</p>
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/apps/quickbooks">
                                    Connect QuickBooks Online
                                </Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/apps/xero">Connect Xero</Link>
                            </Button>
                        </div>
                    </section>
                )}
                {books.map((b) => (
                    <Mapping
                        key={b.ulid}
                        books={b}
                        ours={ours}
                        manage={can.manage}
                    />
                ))}
            </div>
        </>
    );
}

function Mapping({
    books,
    ours,
    manage,
}: {
    books: Books;
    ours: Ours[];
    manage: boolean;
}) {
    const form = useForm({ map: { ...books.map }, post_from: books.post_from });
    const unmapped = ours.filter((a) => !form.data.map[a.code]).length;

    return (
        <section className="grid gap-4 rounded-lg border p-4">
            <h2 className="text-lg font-medium">
                {books.name}
                {books.org ? ` · ${books.org}` : ''}
            </h2>
            {books.error && <p className="text-destructive">{books.error}</p>}
            {books.waiting?.reason === 'map' && (
                <p className="text-destructive">
                    Posting is waiting for these accounts to be mapped:{' '}
                    {books.waiting.accounts?.join(', ')}.
                </p>
            )}
            {books.waiting?.reason === 'refused' && (
                <p className="text-destructive">
                    {books.name} refused a journal: {books.waiting.message} Fix
                    it there; it is posted again on the next run.
                </p>
            )}
            <form
                className="grid gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/finance/books/${books.ulid}`, {
                        preserveScroll: true,
                    });
                }}
            >
                <label className="grid max-w-xs gap-1">
                    Post journals dated from
                    <Input
                        type="date"
                        value={form.data.post_from}
                        disabled={!manage}
                        onChange={(e) =>
                            form.setData('post_from', e.target.value)
                        }
                    />
                </label>
                <div className="grid gap-2">
                    <p className="text-sm text-muted-foreground">
                        Map each RightAlly account to the account it belongs to
                        in {books.name}.
                        {unmapped > 0 ? ` ${unmapped} still to map.` : ''}
                    </p>
                    {ours.map((a) => (
                        <label
                            key={a.code}
                            className="grid items-center gap-2 sm:grid-cols-2"
                        >
                            <span>
                                {a.code} {a.name}{' '}
                                <span className="text-sm text-muted-foreground">
                                    ({a.type})
                                </span>
                            </span>
                            <select
                                className="h-11 rounded-md border bg-background px-3"
                                disabled={
                                    !manage || books.accounts.length === 0
                                }
                                value={form.data.map[a.code] ?? ''}
                                onChange={(e) =>
                                    form.setData('map', {
                                        ...form.data.map,
                                        [a.code]: e.target.value,
                                    })
                                }
                            >
                                <option value="">Not mapped</option>
                                {books.accounts.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name} ({t.type})
                                    </option>
                                ))}
                            </select>
                        </label>
                    ))}
                </div>
                {Object.values(form.errors).map((m) => (
                    <p key={m} className="text-destructive">
                        {m}
                    </p>
                ))}
                {manage && (
                    <div>
                        <Button disabled={form.processing}>Save</Button>
                    </div>
                )}
            </form>
        </section>
    );
}
