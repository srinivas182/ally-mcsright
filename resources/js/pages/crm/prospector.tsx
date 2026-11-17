import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Prospect = {
    ulid: string;
    kind: 'expired' | 'withdrawn' | 'fsbo';
    address: string;
    reason: string;
    owner: string | null;
    mailing: {
        street?: string;
        city?: string;
        state?: string;
        zip?: string;
    } | null;
    phone: string | null;
    dnc: string | null;
    dnc_words: string | null;
    agent: string | null;
    mine: boolean;
    found: string;
};
type Settings = {
    agent_ids: number[];
    per_agent_daily: number;
    zips: string[];
};
type Registry = {
    area_code: string;
    numbers: number;
    downloaded_on: string;
    fresh: boolean;
};

const kindLabel: Record<Prospect['kind'], string> = {
    expired: 'Expired listing',
    withdrawn: 'Withdrawn listing',
    fsbo: 'For sale by owner',
};

function ProspectCard({ p }: { p: Prospect }) {
    const [phone, setPhone] = useState(p.phone ?? '');
    const opts = { preserveScroll: true };
    const clear = p.dnc === 'clear';

    return (
        <li className="grid gap-2 rounded-lg border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="outline">{kindLabel[p.kind]}</Badge>
                <strong className="text-base">{p.address}</strong>
                {p.agent && !p.mine && (
                    <span className="text-sm text-muted-foreground">
                        · {p.agent}
                    </span>
                )}
            </div>
            <p className="text-sm">{p.reason}</p>
            <p className="text-sm text-muted-foreground">
                {p.owner ? `Owner: ${p.owner}` : 'Owner not looked up yet.'}
                {p.mailing?.street &&
                    ` · Mail to ${p.mailing.street}, ${p.mailing.city ?? ''} ${p.mailing.state ?? ''} ${p.mailing.zip ?? ''}`}
            </p>
            {p.dnc_words && (
                <p
                    className={`rounded p-2 text-sm ${clear ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200' : 'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200'}`}
                >
                    {p.dnc_words}
                </p>
            )}
            <div className="flex flex-wrap items-end gap-2">
                {!p.owner && (
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(`/prospector/${p.ulid}/owner`, {}, opts)
                        }
                    >
                        Find the owner
                    </Button>
                )}
                <form
                    className="flex items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            `/prospector/${p.ulid}/phone`,
                            { phone },
                            opts,
                        );
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        A phone number you found
                        <Input
                            className="w-40"
                            inputMode="tel"
                            value={phone}
                            onChange={(e) => setPhone(e.target.value)}
                        />
                    </label>
                    <Button variant="outline" disabled={phone.length < 10}>
                        Check it
                    </Button>
                </form>
                {clear && p.phone && (
                    <Button variant="outline" asChild>
                        <a href={`tel:${p.phone}`}>Call</a>
                    </Button>
                )}
                <Button
                    onClick={() =>
                        router.post(`/prospector/${p.ulid}/contact`, {}, opts)
                    }
                >
                    {clear
                        ? 'Add to my contacts and start the plan'
                        : 'Add to my contacts (cards only)'}
                </Button>
                <button
                    type="button"
                    className="text-sm underline"
                    onClick={() =>
                        router.post(`/prospector/${p.ulid}/dismiss`, {}, opts)
                    }
                >
                    Not for me
                </button>
            </div>
        </li>
    );
}

/**
 * Lead-IQ Prospector: owners of expired and withdrawn listings and for-sale-by-owner homes, each with the reason, a
 * Do Not Call check before any call, and cards for everyone else.
 */
export default function Prospector({
    prospects,
    broker,
    feed,
    settings,
    agents,
    registry,
}: {
    prospects: Prospect[];
    broker: boolean;
    feed: boolean;
    settings: Settings | null;
    agents: { id: number; name: string }[];
    registry: Registry[];
}) {
    const fsbo = useForm({
        street: '',
        city: '',
        state: '',
        zip: '',
        note: '',
    });
    const config = useForm({
        agent_ids: settings?.agent_ids ?? [],
        per_agent_daily: settings?.per_agent_daily ?? 5,
        zips: (settings?.zips ?? []).join(', '),
    });
    const upload = useForm<{ file: File | null; downloaded_on: string }>({
        file: null,
        downloaded_on: new Date().toISOString().slice(0, 10),
    });

    return (
        <>
            <Head title="Prospector" />
            <div className="grid gap-5 p-4">
                <PageHeader
                    title="Prospector"
                    description="Owners worth reaching, each with the reason. Calls only to numbers clear of the Do Not Call Registry; everyone else gets a card."
                />

                {prospects.length === 0 ? (
                    <p className="rounded border p-4 text-muted-foreground">
                        No prospects right now.{' '}
                        {feed
                            ? 'New expired and withdrawn listings arrive each morning.'
                            : 'Expired and withdrawn listings need a back-office MLS licence; you can still add for-sale-by-owner homes below.'}
                    </p>
                ) : (
                    <ul className="grid gap-3">
                        {prospects.map((p) => (
                            <ProspectCard key={p.ulid} p={p} />
                        ))}
                    </ul>
                )}

                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="font-semibold">
                        Add a for-sale-by-owner home
                    </h2>
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            fsbo.post('/prospector/fsbo', {
                                preserveScroll: true,
                                onSuccess: () => fsbo.reset(),
                            });
                        }}
                    >
                        <Input
                            aria-label="Street address"
                            placeholder="Street address"
                            className="w-56"
                            value={fsbo.data.street}
                            onChange={(e) =>
                                fsbo.setData('street', e.target.value)
                            }
                        />
                        <Input
                            aria-label="City"
                            placeholder="City"
                            className="w-32"
                            value={fsbo.data.city}
                            onChange={(e) =>
                                fsbo.setData('city', e.target.value)
                            }
                        />
                        <Input
                            aria-label="State"
                            placeholder="TX"
                            className="w-16"
                            maxLength={2}
                            value={fsbo.data.state}
                            onChange={(e) =>
                                fsbo.setData('state', e.target.value)
                            }
                        />
                        <Input
                            aria-label="ZIP"
                            placeholder="ZIP"
                            className="w-24"
                            value={fsbo.data.zip}
                            onChange={(e) =>
                                fsbo.setData('zip', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Where you saw it"
                            placeholder="Where you saw it (sign, ad)"
                            className="w-56"
                            value={fsbo.data.note}
                            onChange={(e) =>
                                fsbo.setData('note', e.target.value)
                            }
                        />
                        <Button disabled={fsbo.processing || !fsbo.data.street}>
                            Add
                        </Button>
                    </form>
                </section>

                {broker && settings && (
                    <section className="grid gap-3 rounded-lg border p-4 text-sm">
                        <h2 className="text-base font-semibold">
                            For the broker
                        </h2>
                        <form
                            className="grid gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                config.post('/prospector/settings', {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <p>Who gets prospects</p>
                            <div className="flex flex-wrap gap-3">
                                {agents.map((a) => (
                                    <label
                                        key={a.id}
                                        className="flex items-center gap-1"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={config.data.agent_ids.includes(
                                                a.id,
                                            )}
                                            onChange={(e) =>
                                                config.setData(
                                                    'agent_ids',
                                                    e.target.checked
                                                        ? [
                                                              ...config.data
                                                                  .agent_ids,
                                                              a.id,
                                                          ]
                                                        : config.data.agent_ids.filter(
                                                              (x) => x !== a.id,
                                                          ),
                                                )
                                            }
                                        />
                                        {a.name}
                                    </label>
                                ))}
                            </div>
                            <div className="flex flex-wrap gap-3">
                                <label className="grid gap-1">
                                    Most a day for each agent
                                    <Input
                                        type="number"
                                        min={1}
                                        max={50}
                                        className="w-24"
                                        value={config.data.per_agent_daily}
                                        onChange={(e) =>
                                            config.setData(
                                                'per_agent_daily',
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                </label>
                                <label className="grid gap-1">
                                    ZIP codes to watch (empty: all)
                                    <Input
                                        className="w-72"
                                        value={config.data.zips}
                                        onChange={(e) =>
                                            config.setData(
                                                'zips',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                            </div>
                            <Button
                                className="justify-self-start"
                                disabled={config.processing}
                            >
                                Save
                            </Button>
                        </form>

                        <div className="grid gap-2 border-t pt-3">
                            <h3 className="font-semibold">
                                Do Not Call Registry
                            </h3>
                            <p className="text-muted-foreground">
                                Before anyone cold-calls, the numbers must be
                                checked against the National Do Not Call
                                Registry, using your brokerage's own free or
                                paid registry account at
                                telemarketing.donotcall.gov. Download the list
                                for your area codes there each month (it must be
                                no older than 31 days) and upload it here. Only
                                a hash of each number is kept.
                            </p>
                            {registry.length > 0 && (
                                <ul className="flex flex-wrap gap-2">
                                    {registry.map((r) => (
                                        <li key={r.area_code}>
                                            <Badge
                                                variant={
                                                    r.fresh
                                                        ? 'outline'
                                                        : 'destructive'
                                                }
                                            >
                                                {r.area_code}:{' '}
                                                {r.numbers.toLocaleString()}{' '}
                                                numbers, {r.downloaded_on}
                                                {r.fresh ? '' : ' (too old)'}
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <form
                                className="flex flex-wrap items-end gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    upload.post('/prospector/registry', {
                                        preserveScroll: true,
                                        forceFormData: true,
                                    });
                                }}
                            >
                                <label className="grid gap-1">
                                    Registry file (.txt or .csv)
                                    <input
                                        type="file"
                                        accept=".txt,.csv"
                                        onChange={(e) =>
                                            upload.setData(
                                                'file',
                                                e.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                </label>
                                <label className="grid gap-1">
                                    Downloaded on
                                    <Input
                                        type="date"
                                        className="w-40"
                                        value={upload.data.downloaded_on}
                                        onChange={(e) =>
                                            upload.setData(
                                                'downloaded_on',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <Button
                                    variant="outline"
                                    disabled={
                                        upload.processing || !upload.data.file
                                    }
                                >
                                    Upload
                                </Button>
                            </form>
                            {Object.values(upload.errors).map((m) => (
                                <p key={m} className="text-red-700">
                                    {m}
                                </p>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}
