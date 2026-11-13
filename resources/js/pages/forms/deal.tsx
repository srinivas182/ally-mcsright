import { Head, Link, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    deal: { ulid: string; street: string };
    packets: {
        ulid: string;
        title: string;
        status: string;
        created_at: string;
    }[];
    templates: { ulid: string; name: string }[];
};

/** A deal's forms: packets to fill and sign. */
export default function DealForms({ deal, packets, templates }: Props) {
    const form = useForm<{ title: string; templates: string[] }>({
        title: '',
        templates: [],
    });
    const toggle = (u: string) =>
        form.setData(
            'templates',
            form.data.templates.includes(u)
                ? form.data.templates.filter((x) => x !== u)
                : [...form.data.templates, u],
        );

    return (
        <>
            <Head title={`Forms — ${deal.street}`} />
            <div className="flex max-w-3xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Forms and signatures"
                    description={deal.street}
                    actions={
                        <Link
                            className="text-sm underline"
                            href={`/deals/${deal.ulid}`}
                        >
                            Back to the deal
                        </Link>
                    }
                />
                <ul className="divide-y rounded-lg border text-sm">
                    {packets.length === 0 && (
                        <li className="p-3 text-muted-foreground">
                            No forms yet.
                        </li>
                    )}
                    {packets.map((p) => (
                        <li
                            key={p.ulid}
                            className="flex items-center justify-between gap-2 p-3"
                        >
                            <Link
                                className="font-medium hover:underline"
                                href={`/forms/packets/${p.ulid}`}
                            >
                                {p.title}
                            </Link>
                            <Badge variant="outline" className="capitalize">
                                {p.status}
                            </Badge>
                        </li>
                    ))}
                </ul>
                <form
                    className="grid gap-2 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/forms/deals/${deal.ulid}/packets`);
                    }}
                >
                    <h2 className="font-semibold">Prepare forms</h2>
                    {templates.length === 0 ? (
                        <p className="text-muted-foreground">
                            No forms set up yet — add them in Settings › Forms.
                        </p>
                    ) : (
                        <div className="grid gap-1">
                            {templates.map((t) => (
                                <label
                                    key={t.ulid}
                                    className="flex items-center gap-2"
                                >
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={form.data.templates.includes(
                                            t.ulid,
                                        )}
                                        onChange={() => toggle(t.ulid)}
                                    />{' '}
                                    {t.name}
                                </label>
                            ))}
                        </div>
                    )}
                    <Input
                        aria-label="Packet title"
                        placeholder="Title (optional)"
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                    <Button
                        type="submit"
                        size="sm"
                        className="justify-self-start"
                        disabled={form.data.templates.length === 0}
                    >
                        Fill from the deal
                    </Button>
                    {Object.values(form.errors)[0] && (
                        <p role="alert" className="text-destructive">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                </form>
            </div>
        </>
    );
}
