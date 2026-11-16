import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type DateRule = {
    label: string;
    anchor: string;
    days: number;
    business: boolean;
    exact: boolean;
};
type EmailStep = {
    key: string;
    to: string[];
    anchor: string;
    days: number;
    subject: string;
    body: string;
    auto: boolean;
};
type Playbook = {
    key: string;
    name: string;
    items: [string, boolean][];
    dates: DateRule[];
    emails: EmailStep[];
};
type Props = {
    playbooks: Playbook[];
    anchors: Record<string, string>;
    roles: string[];
    canEdit: boolean;
};

const select = 'rounded border bg-background px-2 py-1';

function Editor({
    p,
    anchors,
    roles,
    canEdit,
}: {
    p: Playbook;
    anchors: Record<string, string>;
    roles: string[];
    canEdit: boolean;
}) {
    const form = useForm({ items: p.items, dates: p.dates, emails: p.emails });
    const setItem = (n: number, v: [string, boolean]) =>
        form.setData(
            'items',
            form.data.items.map((x, i) => (i === n ? v : x)),
        );
    const setDate = (n: number, v: Partial<DateRule>) =>
        form.setData(
            'dates',
            form.data.dates.map((x, i) => (i === n ? { ...x, ...v } : x)),
        );
    const setEmail = (n: number, v: Partial<EmailStep>) =>
        form.setData(
            'emails',
            form.data.emails.map((x, i) => (i === n ? { ...x, ...v } : x)),
        );
    const drop = (k: 'items' | 'dates' | 'emails', n: number) =>
        form.setData(
            k,
            (form.data[k] as unknown[]).filter((_, i) => i !== n) as never,
        );

    return (
        <form
            className="grid gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/deals/playbooks/${p.key}`, {
                    preserveScroll: true,
                });
            }}
        >
            <fieldset className="grid gap-2" disabled={!canEdit}>
                <legend className="font-medium">
                    Documents it adds to the checklist
                </legend>
                {form.data.items.map(([label, required], n) => (
                    <span key={n} className="flex flex-wrap items-center gap-2">
                        <Input
                            aria-label="Document"
                            className="max-w-md"
                            value={label}
                            onChange={(e) =>
                                setItem(n, [e.target.value, required])
                            }
                        />
                        <label className="flex items-center gap-1">
                            <input
                                type="checkbox"
                                checked={required}
                                onChange={(e) =>
                                    setItem(n, [label, e.target.checked])
                                }
                            />
                            Needed to close
                        </label>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => drop('items', n)}
                        >
                            Remove
                        </Button>
                    </span>
                ))}
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="justify-self-start"
                    onClick={() =>
                        form.setData('items', [...form.data.items, ['', true]])
                    }
                >
                    Add a document
                </Button>
            </fieldset>
            <fieldset className="grid gap-2" disabled={!canEdit}>
                <legend className="font-medium">
                    Dates it puts on the timeline
                </legend>
                {form.data.dates.map((d, n) => (
                    <span key={n} className="flex flex-wrap items-center gap-2">
                        <Input
                            aria-label="Date name"
                            className="max-w-sm"
                            value={d.label}
                            onChange={(e) =>
                                setDate(n, { label: e.target.value })
                            }
                        />
                        <Input
                            aria-label="Days"
                            type="number"
                            className="w-24"
                            value={d.days}
                            onChange={(e) =>
                                setDate(n, {
                                    days: Number(e.target.value),
                                })
                            }
                        />
                        <span>days from</span>
                        <select
                            aria-label="From"
                            className={select}
                            value={d.anchor}
                            onChange={(e) =>
                                setDate(n, { anchor: e.target.value })
                            }
                        >
                            {Object.entries(anchors).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                        </select>
                        <label className="flex items-center gap-1">
                            <input
                                type="checkbox"
                                checked={d.business}
                                onChange={(e) =>
                                    setDate(n, {
                                        business: e.target.checked,
                                    })
                                }
                            />
                            Business days
                        </label>
                        <label
                            className="flex items-center gap-1"
                            title="Deadlines fixed by law (like 1031) never move for weekends or holidays"
                        >
                            <input
                                type="checkbox"
                                checked={d.exact}
                                onChange={(e) =>
                                    setDate(n, { exact: e.target.checked })
                                }
                            />
                            Never moves
                        </label>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => drop('dates', n)}
                        >
                            Remove
                        </Button>
                    </span>
                ))}
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="justify-self-start"
                    onClick={() =>
                        form.setData('dates', [
                            ...form.data.dates,
                            {
                                label: '',
                                anchor: 'contract',
                                days: 0,
                                business: false,
                                exact: false,
                            },
                        ])
                    }
                >
                    Add a date
                </Button>
            </fieldset>
            <fieldset className="grid gap-3" disabled={!canEdit}>
                <legend className="font-medium">
                    Emails to people on the deal
                </legend>
                <p className="text-muted-foreground">
                    Use {'{street}'}, {'{agent}'}, {'{contract}'} and{' '}
                    {'{closing}'}. Emails wait for the agent to send them unless
                    you tick &quot;Send by itself&quot;. They go from RightAlly
                    with replies to the agent.
                </p>
                {form.data.emails.map((e, n) => (
                    <div key={n} className="grid gap-2 rounded-md border p-2">
                        <span className="flex flex-wrap items-center gap-2">
                            <Input
                                aria-label="Subject"
                                className="max-w-md"
                                value={e.subject}
                                onChange={(ev) =>
                                    setEmail(n, {
                                        subject: ev.target.value,
                                    })
                                }
                            />
                            <Input
                                aria-label="Days"
                                type="number"
                                className="w-24"
                                value={e.days}
                                onChange={(ev) =>
                                    setEmail(n, {
                                        days: Number(ev.target.value),
                                    })
                                }
                            />
                            <span>days from</span>
                            <select
                                aria-label="From"
                                className={select}
                                value={e.anchor}
                                onChange={(ev) =>
                                    setEmail(n, {
                                        anchor: ev.target.value,
                                    })
                                }
                            >
                                {Object.entries(anchors).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                            <label className="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    checked={e.auto}
                                    onChange={(ev) =>
                                        setEmail(n, {
                                            auto: ev.target.checked,
                                        })
                                    }
                                />
                                Send by itself
                            </label>
                        </span>
                        <span className="flex flex-wrap gap-3">
                            {roles.map((r) => (
                                <label
                                    key={r}
                                    className="flex items-center gap-1"
                                >
                                    <input
                                        type="checkbox"
                                        checked={e.to.includes(r)}
                                        onChange={(ev) =>
                                            setEmail(n, {
                                                to: ev.target.checked
                                                    ? [...e.to, r]
                                                    : e.to.filter(
                                                          (x) => x !== r,
                                                      ),
                                            })
                                        }
                                    />
                                    {r.replace('_', '-')}
                                </label>
                            ))}
                        </span>
                        <textarea
                            aria-label="Email"
                            className="min-h-24 rounded border bg-background p-2"
                            value={e.body}
                            onChange={(ev) =>
                                setEmail(n, { body: ev.target.value })
                            }
                        />
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="justify-self-start"
                            onClick={() => drop('emails', n)}
                        >
                            Remove this email
                        </Button>
                    </div>
                ))}
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="justify-self-start"
                    onClick={() =>
                        form.setData('emails', [
                            ...form.data.emails,
                            {
                                key: '',
                                to: ['title'],
                                anchor: 'contract',
                                days: 0,
                                subject: '',
                                body: '',
                                auto: false,
                            },
                        ])
                    }
                >
                    Add an email
                </Button>
            </fieldset>
            {Object.values(form.errors)[0] && (
                <p role="alert" className="text-destructive">
                    {Object.values(form.errors)[0]}
                </p>
            )}
            {canEdit && (
                <span className="flex gap-2">
                    <Button type="submit" size="sm" disabled={form.processing}>
                        Save {p.name}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            router.delete(`/deals/playbooks/${p.key}`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Back to the RightAlly version
                    </Button>
                </span>
            )}
        </form>
    );
}

/** Deal-type playbooks: what each kind of deal adds, editable by the brokerage (specs/ai/02, DQ5a). */
export default function Playbooks({
    playbooks,
    anchors,
    roles,
    canEdit,
}: Props) {
    const [open, setOpen] = useState(playbooks[0]?.key ?? '');

    return (
        <>
            <Head title="Deal playbooks" />
            <div className="flex flex-col gap-4 p-4 text-sm md:p-6">
                <PageHeader
                    title="Deal playbooks"
                    description="Each kind of deal adds its own documents, dates and emails. Ready to use; change them to match how your brokerage works."
                />
                <nav className="flex flex-wrap gap-2" aria-label="Playbooks">
                    {playbooks.map((p) => (
                        <Button
                            key={p.key}
                            size="sm"
                            variant={p.key === open ? 'default' : 'outline'}
                            onClick={() => setOpen(p.key)}
                        >
                            {p.name}
                            <Badge variant="secondary" className="ml-1">
                                {p.items.length +
                                    p.dates.length +
                                    p.emails.length}
                            </Badge>
                        </Button>
                    ))}
                </nav>
                {playbooks
                    .filter((p) => p.key === open)
                    .map((p) => (
                        <section key={p.key} className="rounded-lg border p-4">
                            <Editor
                                p={p}
                                anchors={anchors}
                                roles={roles}
                                canEdit={canEdit}
                            />
                        </section>
                    ))}
            </div>
        </>
    );
}

Playbooks.layout = { breadcrumbs: [{ title: 'Deals', href: '/deals' }] };
