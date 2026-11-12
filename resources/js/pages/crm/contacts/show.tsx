import { Head, Link, router, useForm } from '@inertiajs/react';
import { MailIcon, MessageSquareIcon, PhoneIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { AuditEventRow, type AuditRow } from '@/components/audit-row';
import { AiBrief, type Brief } from '@/components/crm/ai-brief';
import { ContactPicker } from '@/components/crm/contact-picker';
import { QuickUpdate } from '@/components/crm/quick-update';
import { AddTask, TaskList, type TaskRow } from '@/components/crm/task-list';
import { Timeline, type TimelineItem } from '@/components/crm/timeline';
import { FormField } from '@/components/ui/form-field';
import { FormSection } from '@/components/ui/form-section';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatUsPhone } from '@/components/ui/number-inputs';

type Contact = {
    ulid: string;
    name: string;
    first_name: string;
    last_name: string;
    company_name: string | null;
    origin: 'personal' | 'company';
    source: string;
    lifecycle: string;
    owner: string | null;
    owner_user_id: number | null;
    emails: { address: string; label: string; primary: boolean }[];
    phones: {
        e164: string;
        label: string;
        primary: boolean;
        can_text: boolean;
    }[];
    address: {
        line1: string | null;
        line2: string | null;
        city: string | null;
        state: string | null;
        zip: string | null;
    };
    birthday: string | null;
    consent: {
        email: boolean;
        sms: boolean;
        do_not_call: boolean;
        sms_method: string | null;
        auto_text_allowed: boolean;
        default_wording: string;
        history: {
            channel: string;
            action: string;
            method: string;
            occurred_at: string;
        }[];
    };
};

type Props = {
    contact: Contact;
    history: AuditRow[];
    canDownloadEvidence: boolean;
    memories: { id: number; category: string; detail: string }[];
    referral: {
        by: { ulid: string; name: string } | null;
        stats: { referred: number; closed: number };
    };
    score: {
        buyer: number;
        seller: number;
        timeframe: string;
        reasons: string[];
    } | null;
    intents: {
        id: number;
        kind: string;
        summary: string;
        due_on: string | null;
    }[];
    rhythm: string | null;
    closedOn: string | null;
    plans: { ulid: string; name: string }[];
    enrollments: { id: number; plan: string; next_due_at: string | null }[];
    brief: Brief;
    tags: { id: number; name: string }[];
    pipelines: {
        ulid: string;
        name: string;
        stages: { ulid: string; name: string }[];
    }[];
    stage: string | null;
    timeline: TimelineItem[];
    tasks: TaskRow[];
    households: {
        ulid: string;
        name: string;
        members: { ulid: string; name: string }[];
    }[];
    relationships: { relation: string; ulid: string; name: string }[];
    canManage: boolean;
    agents: { id: number; name: string }[];
    lifecycles: string[];
    relations: string[];
};

const label = (v: string) => v.replace(/_/g, ' ');

function FollowUpPanel({
    base,
    plans,
    enrollments,
    rhythm,
    closedOn,
}: {
    base: string;
    plans: { ulid: string; name: string }[];
    enrollments: { id: number; plan: string; next_due_at: string | null }[];
    rhythm: string | null;
    closedOn: string | null;
}) {
    return (
        <div className="grid gap-2 rounded-lg border p-4 text-sm">
            <p className="font-medium">Keep in touch</p>
            <label className="flex items-center justify-between gap-2">
                <span>Relationship</span>
                <select
                    aria-label="Relationship ranking"
                    className="rounded border bg-background px-2 py-1"
                    value={rhythm ?? ''}
                    onChange={(e) =>
                        router.patch(
                            base,
                            { rhythm: e.target.value || null },
                            { preserveScroll: true },
                        )
                    }
                >
                    <option value="">Not ranked</option>
                    <option value="A">A · every month</option>
                    <option value="B">B · every 2 months</option>
                    <option value="C">C · every quarter</option>
                    <option value="D">D · twice a year</option>
                </select>
            </label>
            <label className="flex items-center justify-between gap-2">
                <span>Closing date</span>
                <input
                    type="date"
                    className="rounded border bg-background px-2 py-1"
                    defaultValue={closedOn ?? ''}
                    onBlur={(e) =>
                        e.target.value !== (closedOn ?? '') &&
                        router.patch(
                            base,
                            { closed_on: e.target.value || null },
                            { preserveScroll: true },
                        )
                    }
                />
            </label>
            <p className="mt-2 font-medium">Follow-up plan</p>
            {enrollments.map((e) => (
                <p
                    key={e.id}
                    className="flex items-center justify-between gap-2"
                >
                    <span>
                        {e.plan}
                        {e.next_due_at && (
                            <span className="block text-xs text-muted-foreground">
                                Next step{' '}
                                {new Date(e.next_due_at).toLocaleDateString()}
                            </span>
                        )}
                    </span>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            router.post(
                                `/plan-enrollments/${e.id}/stop`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Stop
                    </Button>
                </p>
            ))}
            <select
                aria-label="Start a follow-up plan"
                className="rounded border bg-background px-2 py-1"
                value=""
                onChange={(e) =>
                    e.target.value &&
                    router.post(
                        `${base}/plans`,
                        { plan: e.target.value },
                        { preserveScroll: true },
                    )
                }
            >
                <option value="">Start a plan…</option>
                {plans.map((p) => (
                    <option key={p.ulid} value={p.ulid}>
                        {p.name}
                    </option>
                ))}
            </select>
        </div>
    );
}

const timeframes: Record<string, string> = {
    now: 'now',
    '3m': 'within 3 months',
    '6m': 'within 6 months',
    '12m': 'within a year',
    later: 'later',
    unknown: 'not known',
};

function OpportunityPanel({
    base,
    score,
    intents,
}: {
    base: string;
    score: Props['score'];
    intents: Props['intents'];
}) {
    return (
        <div className="grid gap-2 rounded-lg border p-4 text-sm">
            <div className="flex items-center justify-between gap-2">
                <p className="font-medium">Opportunity</p>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        router.post(
                            `${base}/analyse`,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Read notes
                </Button>
            </div>
            {score ? (
                <>
                    <p>
                        Buyer {score.buyer} · Seller {score.seller} · Timing:{' '}
                        {timeframes[score.timeframe] ?? score.timeframe}
                    </p>
                    {score.reasons.length > 0 && (
                        <ul className="list-disc pl-4 text-muted-foreground">
                            {score.reasons.map((r) => (
                                <li key={r}>{r}</li>
                            ))}
                        </ul>
                    )}
                    <p className="text-xs text-muted-foreground">
                        A signal, not a certainty — you know this person best.
                    </p>
                </>
            ) : (
                <p className="text-muted-foreground">Not scored yet.</p>
            )}
            {intents.length > 0 && (
                <p className="mt-1 font-medium">Plans they mentioned</p>
            )}
            {intents.map((i) => (
                <div
                    key={i.id}
                    className="flex items-start justify-between gap-2"
                >
                    <span>
                        {i.summary}
                        {i.due_on && (
                            <span className="block text-xs text-muted-foreground">
                                {new Date(i.due_on).toLocaleDateString(
                                    undefined,
                                    { month: 'short', year: 'numeric' },
                                )}
                            </span>
                        )}
                    </span>
                    <span className="flex shrink-0 gap-1">
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.post(
                                    `/intents/${i.id}`,
                                    { status: 'done' },
                                    { preserveScroll: true },
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
                                    `/intents/${i.id}`,
                                    { status: 'dismissed' },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Not right
                        </Button>
                    </span>
                </div>
            ))}
        </div>
    );
}

function PeoplePanel({
    base,
    memories,
    referral,
}: {
    base: string;
    memories: Props['memories'];
    referral: Props['referral'];
}) {
    return (
        <div className="grid gap-2 rounded-lg border p-4 text-sm">
            <p className="font-medium">Personal notes</p>
            <p className="text-xs text-muted-foreground">
                Small details for a personal touch. Never sensitive topics. Only
                you see them.
            </p>
            {memories.map((m) => (
                <p
                    key={m.id}
                    className="flex items-center justify-between gap-2"
                >
                    <span>
                        <span className="text-muted-foreground capitalize">
                            {m.category}:{' '}
                        </span>
                        {m.detail}
                    </span>
                    <Button
                        size="sm"
                        variant="ghost"
                        aria-label={`Remove ${m.detail}`}
                        onClick={() =>
                            router.delete(`/memories/${m.id}`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        ×
                    </Button>
                </p>
            ))}
            <form
                className="flex gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    const els = e.currentTarget.elements;
                    const detail = els.namedItem('detail') as HTMLInputElement;
                    const category = els.namedItem(
                        'category',
                    ) as HTMLSelectElement;
                    router.post(
                        `${base}/memories`,
                        { category: category.value, detail: detail.value },
                        {
                            preserveScroll: true,
                            onSuccess: () => (detail.value = ''),
                        },
                    );
                }}
            >
                <select
                    name="category"
                    aria-label="Kind of note"
                    className="rounded border bg-background px-1 py-1"
                >
                    <option value="family">Family</option>
                    <option value="pet">Pet</option>
                    <option value="hobby">Hobby</option>
                    <option value="preference">Likes</option>
                    <option value="milestone">Milestone</option>
                    <option value="other">Other</option>
                </select>
                <Input
                    name="detail"
                    aria-label="Personal note"
                    placeholder="e.g. Dog called Luna"
                />
            </form>
            <p className="mt-2 font-medium">Referrals</p>
            {referral.by ? (
                <p>
                    Referred by{' '}
                    <a
                        className="text-primary hover:underline"
                        href={`/contacts/${referral.by.ulid}`}
                    >
                        {referral.by.name}
                    </a>
                </p>
            ) : (
                <ContactPicker
                    label="Referred by…"
                    onPick={(c) =>
                        router.post(
                            `${base}/referred-by`,
                            { referrer: c.ulid },
                            { preserveScroll: true },
                        )
                    }
                />
            )}
            {referral.stats.referred > 0 && (
                <p className="text-muted-foreground">
                    Has referred {referral.stats.referred}{' '}
                    {referral.stats.referred === 1 ? 'person' : 'people'} (
                    {referral.stats.closed} closed)
                </p>
            )}
        </div>
    );
}

function ConsentPanel({
    base,
    consent,
}: {
    base: string;
    consent: Contact['consent'];
}) {
    const act = (action: string, extra: Record<string, string> = {}) =>
        router.post(
            `${base}/consent`,
            { action, ...extra },
            { preserveScroll: true },
        );

    return (
        <div className="grid gap-2 rounded-lg border p-4 text-sm">
            <p className="font-medium">Consent</p>
            <p>
                Texting:{' '}
                {consent.sms
                    ? `yes (${(consent.sms_method ?? '').replace('_', ' ')}${consent.auto_text_allowed ? ', automated texts allowed' : ', manual texts only'})`
                    : 'not given'}
            </p>
            <div className="flex flex-wrap gap-2">
                {consent.sms ? (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => act('sms_opt_out')}
                    >
                        Opted out of texts
                    </Button>
                ) : (
                    <>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                act('sms_opt_in', {
                                    method: 'signed',
                                    wording: consent.default_wording,
                                })
                            }
                        >
                            Signed consent received
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                act('sms_opt_in', {
                                    method: 'verbal',
                                    wording: consent.default_wording,
                                })
                            }
                        >
                            Agreed verbally
                        </Button>
                    </>
                )}
                <Button
                    size="sm"
                    variant={consent.do_not_call ? 'destructive' : 'outline'}
                    onClick={() =>
                        act(consent.do_not_call ? 'dnc_off' : 'dnc_on')
                    }
                >
                    {consent.do_not_call ? 'Remove Do Not Call' : 'Do Not Call'}
                </Button>
            </div>
            {consent.history.length > 0 && (
                <details>
                    <summary className="cursor-pointer text-muted-foreground">
                        History
                    </summary>
                    <ul className="mt-1 grid gap-1 text-xs text-muted-foreground">
                        {consent.history.map((h) => (
                            <li key={h.occurred_at + h.channel + h.action}>
                                {new Date(h.occurred_at).toLocaleString()} ·{' '}
                                {h.channel} {h.action.replace('_', ' ')} (
                                {h.method.replace('_', ' ')})
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}

export default function ContactShow({
    canDownloadEvidence,
    history,
    memories,
    referral,
    score,
    intents,
    rhythm,
    closedOn,
    plans,
    enrollments,
    brief,
    tags,
    pipelines,
    stage,
    timeline,
    tasks,
    contact,
    households,
    relationships,
    canManage,
    agents,
    lifecycles,
}: Props) {
    const base = `/contacts/${contact.ulid}`;
    const form = useForm({
        first_name: contact.first_name,
        last_name: contact.last_name,
        address_line1: contact.address.line1 ?? '',
        city: contact.address.city ?? '',
        state: contact.address.state ?? '',
        zip: contact.address.zip ?? '',
        birthday: contact.birthday ?? '',
    });

    return (
        <>
            <Head title={contact.name} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={contact.name}
                    description={
                        <span className="flex flex-wrap items-center gap-2">
                            <Badge
                                variant={
                                    contact.origin === 'personal'
                                        ? 'secondary'
                                        : 'outline'
                                }
                            >
                                {contact.origin === 'personal'
                                    ? 'Personal sphere'
                                    : 'Company contact'}
                            </Badge>
                            <span className="capitalize">
                                Source: {label(contact.source)}
                            </span>
                            {contact.owner && (
                                <span>· Owner: {contact.owner}</span>
                            )}
                        </span>
                    }
                    actions={
                        canManage && (
                            <Select
                                value={contact.lifecycle}
                                onValueChange={(lifecycle) =>
                                    router.patch(
                                        base,
                                        { lifecycle },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <SelectTrigger
                                    aria-label="Stage"
                                    className="w-44 capitalize"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {lifecycles.map((l) => (
                                        <SelectItem
                                            key={l}
                                            value={l}
                                            className="capitalize"
                                        >
                                            {label(l)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )
                    }
                />

                <div className="flex flex-wrap gap-2">
                    {contact.phones.map((p) => (
                        <span key={p.e164} className="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                disabled={contact.consent.do_not_call}
                            >
                                <a href={`tel:${p.e164}`}>
                                    <PhoneIcon aria-hidden="true" />{' '}
                                    {formatUsPhone(p.e164)}
                                </a>
                            </Button>
                            {p.can_text && (
                                <Button variant="outline" size="sm" asChild>
                                    <a href={`sms:${p.e164}`}>
                                        <MessageSquareIcon aria-hidden="true" />{' '}
                                        Text
                                    </a>
                                </Button>
                            )}
                        </span>
                    ))}
                    {contact.emails.map((e) => (
                        <Button
                            key={e.address}
                            variant="outline"
                            size="sm"
                            asChild
                        >
                            <a href={`mailto:${e.address}`}>
                                <MailIcon aria-hidden="true" /> {e.address}
                            </a>
                        </Button>
                    ))}
                    {contact.consent.do_not_call && (
                        <Badge variant="destructive">Do not call</Badge>
                    )}
                    {!contact.consent.sms && contact.phones.length > 0 && (
                        <Badge variant="secondary">
                            No texting consent yet
                        </Badge>
                    )}
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="grid gap-6 lg:col-span-2">
                        <AiBrief
                            contactUlid={contact.ulid}
                            brief={brief}
                            phone={contact.phones[0]?.e164 ?? null}
                            email={contact.emails[0]?.address ?? null}
                        />
                        <QuickUpdate contactUlid={contact.ulid} />
                        <Timeline contactUlid={contact.ulid} items={timeline} />
                        {canManage && (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.patch(base, { preserveScroll: true });
                                }}
                                className="grid gap-6"
                            >
                                <FormSection title="Details">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <FormField
                                            label="First name"
                                            error={form.errors.first_name}
                                        >
                                            {(c) => (
                                                <Input
                                                    {...c}
                                                    value={form.data.first_name}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'first_name',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                        <FormField
                                            label="Last name"
                                            error={form.errors.last_name}
                                        >
                                            {(c) => (
                                                <Input
                                                    {...c}
                                                    value={form.data.last_name}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'last_name',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                    </div>
                                    <FormField
                                        label="Street address"
                                        error={form.errors.address_line1}
                                    >
                                        {(c) => (
                                            <Input
                                                {...c}
                                                autoComplete="off"
                                                value={form.data.address_line1}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'address_line1',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>
                                    <div className="grid gap-4 sm:grid-cols-[2fr_1fr_1fr]">
                                        <FormField
                                            label="City"
                                            error={form.errors.city}
                                        >
                                            {(c) => (
                                                <Input
                                                    {...c}
                                                    value={form.data.city}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'city',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                        <FormField
                                            label="State"
                                            error={form.errors.state}
                                        >
                                            {(c) => (
                                                <Input
                                                    {...c}
                                                    maxLength={2}
                                                    value={form.data.state}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'state',
                                                            e.target.value.toUpperCase(),
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                        <FormField
                                            label="ZIP"
                                            error={form.errors.zip}
                                        >
                                            {(c) => (
                                                <Input
                                                    {...c}
                                                    value={form.data.zip}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'zip',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                    </div>
                                    <FormField
                                        label="Birthday"
                                        error={form.errors.birthday}
                                    >
                                        {(c) => (
                                            <Input
                                                {...c}
                                                type="date"
                                                value={form.data.birthday}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'birthday',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>
                                    <Button
                                        type="submit"
                                        className="justify-self-start"
                                        disabled={form.processing}
                                    >
                                        Save
                                    </Button>
                                </FormSection>
                            </form>
                        )}
                    </div>

                    <aside className="grid content-start gap-4">
                        <div className="grid gap-2 rounded-lg border p-4">
                            <label
                                htmlFor="stage"
                                className="text-sm font-medium"
                            >
                                Pipeline stage
                            </label>
                            <select
                                id="stage"
                                className="rounded-md border bg-background px-2 py-1.5 text-sm"
                                value={stage ?? ''}
                                onChange={(e) =>
                                    router.post(
                                        `${base}/stage`,
                                        { stage: e.target.value },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <option value="" disabled>
                                    Not in a pipeline
                                </option>
                                {pipelines.map((p) => (
                                    <optgroup key={p.ulid} label={p.name}>
                                        {p.stages.map((s) => (
                                            <option key={s.ulid} value={s.ulid}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </optgroup>
                                ))}
                            </select>
                            <p className="mt-2 text-sm font-medium">Tags</p>
                            <div className="flex flex-wrap gap-1">
                                {tags.map((t) => (
                                    <Badge
                                        key={t.id}
                                        variant="secondary"
                                        className="gap-1"
                                    >
                                        {t.name}
                                        <button
                                            type="button"
                                            aria-label={`Remove tag ${t.name}`}
                                            onClick={() =>
                                                router.delete(
                                                    `${base}/tags/${t.id}`,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            ×
                                        </button>
                                    </Badge>
                                ))}
                            </div>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    const input =
                                        e.currentTarget.elements.namedItem(
                                            'tag',
                                        ) as HTMLInputElement;
                                    router.post(
                                        `${base}/tags`,
                                        { tag: input.value },
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => (input.value = ''),
                                        },
                                    );
                                }}
                            >
                                <Input
                                    name="tag"
                                    aria-label="Add a tag"
                                    placeholder="Add a tag and press Enter"
                                />
                            </form>
                        </div>
                        <div className="grid gap-3 rounded-lg border p-4">
                            <p className="text-sm font-medium">Tasks</p>
                            {tasks.length > 0 && (
                                <TaskList tasks={tasks} showContact={false} />
                            )}
                            <AddTask contactUlid={contact.ulid} />
                        </div>
                        {agents.length > 0 && (
                            <div className="grid gap-2 rounded-lg border p-4">
                                <p className="text-sm font-medium">Owner</p>
                                <Select
                                    value={
                                        contact.owner_user_id
                                            ? String(contact.owner_user_id)
                                            : ''
                                    }
                                    onValueChange={(id) =>
                                        router.post(
                                            `${base}/assign`,
                                            { owner_user_id: Number(id) },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <SelectTrigger aria-label="Owner">
                                        <SelectValue placeholder="Unassigned" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {agents.map((a) => (
                                            <SelectItem
                                                key={a.id}
                                                value={String(a.id)}
                                            >
                                                {a.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        <OpportunityPanel
                            base={base}
                            score={score}
                            intents={intents}
                        />
                        <PeoplePanel
                            base={base}
                            memories={memories}
                            referral={referral}
                        />
                        <FollowUpPanel
                            base={base}
                            plans={plans}
                            enrollments={enrollments}
                            rhythm={rhythm}
                            closedOn={closedOn}
                        />
                        {history.length > 0 && (
                            <details className="rounded-lg border p-4 text-sm">
                                <summary className="cursor-pointer font-medium">
                                    Who viewed or changed this contact
                                </summary>
                                {canDownloadEvidence && (
                                    <a
                                        className="mt-2 inline-block text-primary underline"
                                        href={`/contacts/${contact.ulid}/evidence-pack`}
                                    >
                                        Download evidence pack (PDF)
                                    </a>
                                )}
                                <ul className="mt-2 divide-y">
                                    {history.map((e) => (
                                        <AuditEventRow key={e.ulid} e={e} />
                                    ))}
                                </ul>
                            </details>
                        )}
                        <ConsentPanel base={base} consent={contact.consent} />
                        <div className="grid gap-2 rounded-lg border p-4">
                            <p className="text-sm font-medium">
                                Household and relationships
                            </p>
                            {households.length === 0 &&
                                relationships.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        None yet.
                                    </p>
                                )}
                            {households.map((h) => (
                                <p key={h.ulid} className="text-sm">
                                    {h.name}:{' '}
                                    {h.members.map((m) => m.name).join(', ')}
                                </p>
                            ))}
                            {relationships.map((r) => (
                                <p
                                    key={r.ulid + r.relation}
                                    className="text-sm"
                                >
                                    <span className="capitalize">
                                        {r.relation}
                                    </span>
                                    :{' '}
                                    <Link
                                        href={`/contacts/${r.ulid}`}
                                        className="text-primary hover:underline"
                                    >
                                        {r.name}
                                    </Link>
                                </p>
                            ))}
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}

ContactShow.layout = {
    breadcrumbs: [{ title: 'Contacts', href: '/contacts' }],
};
