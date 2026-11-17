import { Head, router, usePage } from '@inertiajs/react';

import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Export = {
    ulid: string;
    status: string;
    bytes: number | null;
    tables: number;
    rows: number;
    files: number;
    created_at: string;
    expires_at: string | null;
};

const size = (b: number | null) =>
    b === null
        ? ''
        : b > 1_000_000
          ? `${(b / 1_000_000).toFixed(1)} MB`
          : `${Math.max(1, Math.round(b / 1000))} KB`;

/** Settings → Your data (DS1): the owner's complete export, protected with a password shown here. */
export default function DataExport({
    exports,
    password,
}: {
    exports: Export[];
    password: { export: string; password: string } | null;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <>
            <Head title="Your data" />
            <div className="grid gap-6 text-base">
                <Heading
                    variant="small"
                    title="Your data"
                    description="Download everything your brokerage has in RightAlly: contacts, deals, documents, money records, settings and files."
                />
                <ul className="list-disc pl-5 text-sm text-muted-foreground">
                    <li>
                        One ZIP file, protected with a password that only you
                        can see here.
                    </li>
                    <li>
                        Each table is a file of JSON lines; your documents and
                        photos are included.
                    </li>
                    <li>
                        Passwords, sign-in secrets and app keys are left out.
                    </li>
                    <li>
                        Ready within minutes for most brokerages. Kept for 7
                        days, then deleted.
                    </li>
                </ul>
                {errors.export && (
                    <p role="alert" className="text-red-700">
                        {errors.export}
                    </p>
                )}
                <Button
                    className="justify-self-start"
                    onClick={() => router.post('/settings/data-export')}
                >
                    Prepare my export
                </Button>
                {exports.length > 0 && (
                    <ul className="grid gap-2">
                        {exports.map((e) => (
                            <li
                                key={e.ulid}
                                className="flex flex-wrap items-center gap-2 rounded border p-3"
                            >
                                <span>{e.created_at.slice(0, 16)}</span>
                                <Badge
                                    variant={
                                        e.status === 'ready'
                                            ? 'default'
                                            : 'outline'
                                    }
                                >
                                    {e.status === 'ready'
                                        ? 'Ready'
                                        : e.status === 'expired'
                                          ? 'Deleted'
                                          : e.status === 'failed'
                                            ? 'Failed'
                                            : 'Preparing…'}
                                </Badge>
                                {e.status === 'ready' && (
                                    <>
                                        <span className="text-sm text-muted-foreground">
                                            {e.rows} records in {e.tables}{' '}
                                            tables · {e.files} files ·{' '}
                                            {size(e.bytes)}
                                        </span>
                                        <Button asChild size="sm">
                                            <a
                                                href={`/settings/data-export/${e.ulid}`}
                                            >
                                                Download
                                            </a>
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/settings/data-export/${e.ulid}/password`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Show the password
                                        </Button>
                                    </>
                                )}
                                {password?.export === e.ulid && (
                                    <code className="w-full rounded bg-muted p-2 text-sm select-all">
                                        {password.password}
                                    </code>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
