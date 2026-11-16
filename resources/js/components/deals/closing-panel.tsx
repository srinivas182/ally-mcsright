import { Link, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type ClosingProps = {
    time: string | null;
    place: string | null;
    appointments: {
        ulid: string;
        kind: string;
        title: string;
        vendor: string | null;
        phone: string | null;
        starts_at: string;
        status: string;
        proposed_at: string | null;
        note: string | null;
    }[];
    kinds: Record<string, string>;
    vendors: {
        ulid: string;
        category: string;
        name: string;
        email: string | null;
        phone: string | null;
    }[];
};

const select = 'rounded border bg-background px-2 py-1';
const vendorFor: Record<string, string> = {
    inspection: 'inspector',
    appraisal: 'appraiser',
    survey: 'surveyor',
};
const when = (iso: string) =>
    new Date(iso).toLocaleString([], {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

/** Appointments with vendors, and closing time and place (specs/ai/02, DQ5b). */
export function ClosingPanel({
    dealUlid,
    locked,
    closing: c,
}: {
    dealUlid: string;
    locked: boolean;
    closing: ClosingProps;
}) {
    const base = `/deals/${dealUlid}`;
    const opts = { preserveScroll: true };
    const details = useForm({
        closing_time: c.time ?? '',
        closing_place: c.place ?? '',
    });
    const book = useForm({
        kind: 'inspection',
        title: '',
        starts_at: '',
        vendor: '',
        vendor_name: '',
        vendor_email: '',
        vendor_phone: '',
    });
    const choices = c.vendors.filter(
        (v) =>
            v.category === (vendorFor[book.data.kind] ?? '') ||
            book.data.kind === 'other',
    );

    return (
        <section
            className="grid gap-3 rounded-lg border p-4 text-sm"
            aria-label="Appointments and closing"
        >
            <div className="flex flex-wrap items-center gap-2">
                <p className="font-medium">Appointments and closing</p>
                <Link
                    href="/deals/vendors"
                    className="text-muted-foreground underline"
                >
                    Preferred vendors
                </Link>
            </div>
            {c.appointments.length > 0 && (
                <ul className="grid gap-1">
                    {c.appointments.map((a) => (
                        <li
                            key={a.ulid}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <Badge
                                variant={
                                    a.status === 'confirmed' ||
                                    a.status === 'done'
                                        ? 'secondary'
                                        : a.status === 'missed'
                                          ? 'destructive'
                                          : 'outline'
                                }
                            >
                                {a.status}
                            </Badge>
                            <span>
                                {a.title}, {when(a.starts_at)}
                                {a.vendor && ` · ${a.vendor}`}
                                {a.phone && ` · ${a.phone}`}
                                {a.proposed_at && (
                                    <strong>
                                        {' '}
                                        · suggests {when(a.proposed_at)}
                                    </strong>
                                )}
                                {a.note && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · “{a.note}”
                                    </span>
                                )}
                            </span>
                            {!locked && a.proposed_at && (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            `${base}/appointments/${a.ulid}/accept`,
                                            {},
                                            opts,
                                        )
                                    }
                                >
                                    Accept the new time
                                </Button>
                            )}
                            {!locked &&
                                !['done', 'cancelled'].includes(a.status) && (
                                    <>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    `${base}/appointments/${a.ulid}/done`,
                                                    {},
                                                    opts,
                                                )
                                            }
                                        >
                                            Done
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    `${base}/appointments/${a.ulid}/cancel`,
                                                    {},
                                                    opts,
                                                )
                                            }
                                        >
                                            Cancel
                                        </Button>
                                    </>
                                )}
                        </li>
                    ))}
                </ul>
            )}
            {!locked && (
                <details>
                    <summary className="cursor-pointer">
                        Book an appointment
                    </summary>
                    <form
                        className="mt-2 grid gap-2 md:grid-cols-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            book.post(`${base}/appointments`, {
                                ...opts,
                                onSuccess: () => book.reset(),
                            });
                        }}
                    >
                        <select
                            aria-label="Kind"
                            className={select}
                            value={book.data.kind}
                            onChange={(e) =>
                                book.setData('kind', e.target.value)
                            }
                        >
                            {Object.entries(c.kinds).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                        </select>
                        <Input
                            aria-label="When"
                            type="datetime-local"
                            value={book.data.starts_at}
                            onChange={(e) =>
                                book.setData('starts_at', e.target.value)
                            }
                        />
                        <select
                            aria-label="Preferred vendor"
                            className={select}
                            value={book.data.vendor}
                            onChange={(e) =>
                                book.setData('vendor', e.target.value)
                            }
                        >
                            <option value="">Someone else (type below)</option>
                            {choices.map((v) => (
                                <option key={v.ulid} value={v.ulid}>
                                    {v.name}
                                </option>
                            ))}
                        </select>
                        {book.data.vendor === '' && (
                            <>
                                <Input
                                    aria-label="Vendor name"
                                    placeholder="Vendor name"
                                    value={book.data.vendor_name}
                                    onChange={(e) =>
                                        book.setData(
                                            'vendor_name',
                                            e.target.value,
                                        )
                                    }
                                />
                                <Input
                                    aria-label="Vendor email"
                                    type="email"
                                    placeholder="Email (to confirm or reschedule)"
                                    value={book.data.vendor_email}
                                    onChange={(e) =>
                                        book.setData(
                                            'vendor_email',
                                            e.target.value,
                                        )
                                    }
                                />
                                <Input
                                    aria-label="Vendor phone"
                                    placeholder="Phone"
                                    value={book.data.vendor_phone}
                                    onChange={(e) =>
                                        book.setData(
                                            'vendor_phone',
                                            e.target.value,
                                        )
                                    }
                                />
                            </>
                        )}
                        <Button
                            type="submit"
                            size="sm"
                            className="justify-self-start"
                            disabled={book.processing}
                        >
                            Book it
                        </Button>
                        {Object.values(book.errors)[0] && (
                            <span className="text-destructive md:col-span-3">
                                {Object.values(book.errors)[0]}
                            </span>
                        )}
                    </form>
                </details>
            )}
            <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    details.post(`${base}/closing`, opts);
                }}
            >
                <label className="grid gap-1">
                    <span className="text-muted-foreground">Closing time</span>
                    <Input
                        type="time"
                        value={details.data.closing_time}
                        disabled={locked}
                        onChange={(e) =>
                            details.setData('closing_time', e.target.value)
                        }
                    />
                </label>
                <label className="grid min-w-64 flex-1 gap-1">
                    <span className="text-muted-foreground">
                        Closing place (title office address)
                    </span>
                    <Input
                        value={details.data.closing_place}
                        disabled={locked}
                        onChange={(e) =>
                            details.setData('closing_place', e.target.value)
                        }
                    />
                </label>
                {!locked && (
                    <Button
                        type="submit"
                        size="sm"
                        disabled={details.processing}
                    >
                        Save
                    </Button>
                )}
            </form>
            <p className="text-xs text-muted-foreground">
                Clients get the closing-day plan 3 days before, in their
                language, with the title company&apos;s phone number from this
                deal for checking wiring instructions.
            </p>
        </section>
    );
}
