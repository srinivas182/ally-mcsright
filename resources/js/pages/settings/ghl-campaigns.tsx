import { Head, Link, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Target = { id: string; name: string };
type Enrolment = {
    ulid: string;
    name: string;
    target_type: string;
    target_name: string;
    source_type: string;
    source_value: string;
    channel: string;
    active: boolean;
    enrolled: number;
    left_out: number;
    last_run_at: string | null;
    last_error: string | null;
};
type Conn = {
    ulid: string;
    brokerage: boolean;
    targets: { workflows: Target[]; campaigns: Target[] };
    error: string | null;
    enrolments: Enrolment[];
};

const select = 'h-12 rounded-md border bg-background px-3';

function NewEnrolment({
    c,
    tags,
    lists,
}: {
    c: Conn;
    tags: string[];
    lists: { key: string; name: string; count: number }[];
}) {
    const form = useForm({
        name: '',
        target_type: c.targets.workflows.length > 0 ? 'workflow' : 'tag',
        target_id: c.targets.workflows[0]?.id ?? '',
        source_type: 'smart_list',
        source_value: lists[0]?.key ?? '',
        channel: 'email',
    });
    const options =
        form.data.target_type === 'workflow'
            ? c.targets.workflows
            : c.targets.campaigns;

    return (
        <form
            className="grid gap-3 rounded-lg border p-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/settings/ghl-campaigns/${c.ulid}`, {
                    preserveScroll: true,
                    onSuccess: () => form.reset('name'),
                });
            }}
        >
            <h3 className="font-medium">Add contacts to a campaign</h3>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="grid gap-1">
                    Who
                    <select
                        className={select}
                        value={`${form.data.source_type}:${form.data.source_value}`}
                        onChange={(e) => {
                            const [type, ...rest] = e.target.value.split(':');
                            form.setData((d) => ({
                                ...d,
                                source_type: type,
                                source_value: rest.join(':'),
                            }));
                        }}
                    >
                        <optgroup label="Smart lists">
                            {lists.map((l) => (
                                <option
                                    key={l.key}
                                    value={`smart_list:${l.key}`}
                                >
                                    {l.name} ({l.count})
                                </option>
                            ))}
                        </optgroup>
                        <optgroup label="Tags">
                            {tags.map((t) => (
                                <option key={t} value={`tag:${t}`}>
                                    Tag: {t}
                                </option>
                            ))}
                        </optgroup>
                    </select>
                    {form.errors.source_value && (
                        <span className="text-destructive">
                            {form.errors.source_value}
                        </span>
                    )}
                </label>
                <label className="grid gap-1">
                    Into
                    <select
                        className={select}
                        value={form.data.target_type}
                        onChange={(e) => {
                            const type = e.target.value;
                            const first =
                                type === 'workflow'
                                    ? c.targets.workflows[0]?.id
                                    : type === 'campaign'
                                      ? c.targets.campaigns[0]?.id
                                      : '';
                            form.setData((d) => ({
                                ...d,
                                target_type: type,
                                target_id: first ?? '',
                            }));
                        }}
                    >
                        <option value="workflow">A workflow</option>
                        <option value="campaign">A campaign</option>
                        <option value="tag">A trigger tag</option>
                    </select>
                </label>
            </div>
            {form.data.target_type === 'tag' ? (
                <label className="grid gap-1">
                    Tag that starts your GoHighLevel automation
                    <Input
                        maxLength={60}
                        value={form.data.target_id}
                        onChange={(e) =>
                            form.setData('target_id', e.target.value)
                        }
                        placeholder="RA: Spring sellers"
                    />
                </label>
            ) : (
                <label className="grid gap-1">
                    {form.data.target_type === 'workflow'
                        ? 'Workflow'
                        : 'Campaign'}
                    <select
                        className={select}
                        value={form.data.target_id}
                        onChange={(e) =>
                            form.setData('target_id', e.target.value)
                        }
                    >
                        {options.map((t) => (
                            <option key={t.id} value={t.id}>
                                {t.name}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            {form.errors.target_id && (
                <span className="text-destructive">
                    {form.errors.target_id}
                </span>
            )}
            <label className="grid gap-1">
                It sends
                <select
                    className={select}
                    value={form.data.channel}
                    onChange={(e) => form.setData('channel', e.target.value)}
                >
                    <option value="email">
                        Emails (people who haven't opted out of email)
                    </option>
                    <option value="sms">
                        Texts (only people with written texting consent)
                    </option>
                    <option value="any">
                        Emails and texts (either is fine)
                    </option>
                </select>
            </label>
            <label className="grid gap-1">
                Name (optional)
                <Input
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
            </label>
            <div>
                <Button type="submit" disabled={form.processing}>
                    Start
                </Button>
            </div>
        </form>
    );
}

/**
 * Settings → GoHighLevel campaigns (specs/connect/15, C7a-2): build the campaign in GoHighLevel once, then choose
 * which RightAlly contacts go in. New matches go in every hour; consent is checked for each person.
 */
export default function GhlCampaigns({
    connections,
    tags,
    lists,
}: {
    connections: Conn[];
    tags: string[];
    lists: { key: string; name: string; count: number }[];
}) {
    return (
        <>
            <Head title="GoHighLevel campaigns" />
            <div className="grid max-w-3xl gap-6 p-4 text-base">
                <PageHeader
                    title="GoHighLevel campaigns"
                    description="Build the campaign in GoHighLevel; choose here which RightAlly contacts go in. New matches go in every hour, and people who opted out never do."
                />
                {connections.length === 0 ? (
                    <EmptyState
                        title="GoHighLevel isn't connected"
                        description="Connect GoHighLevel under Apps first."
                        action={
                            <Button asChild>
                                <Link href="/apps/gohighlevel">
                                    Connect GoHighLevel
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    connections.map((c) => (
                        <section key={c.ulid} className="grid gap-3">
                            <h2 className="font-medium">
                                GoHighLevel{' '}
                                {c.brokerage ? '(brokerage)' : '(yours)'}
                            </h2>
                            {c.error && (
                                <p className="text-destructive">{c.error}</p>
                            )}
                            {c.enrolments.length > 0 && (
                                <ul className="grid gap-2">
                                    {c.enrolments.map((e) => (
                                        <li
                                            key={e.ulid}
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm"
                                        >
                                            <span className="grid gap-1">
                                                <span className="flex items-center gap-2 font-medium">
                                                    {e.name}
                                                    <Badge
                                                        variant={
                                                            e.active
                                                                ? 'default'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {e.active
                                                            ? 'On'
                                                            : 'Paused'}
                                                    </Badge>
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {e.source_type === 'tag'
                                                        ? `Tag ${e.source_value}`
                                                        : 'Smart list'}{' '}
                                                    → {e.target_type} “
                                                    {e.target_name}” ·{' '}
                                                    {e.enrolled} added
                                                    {e.left_out > 0
                                                        ? `, ${e.left_out} left out (consent)`
                                                        : ''}
                                                </span>
                                                {e.last_error && (
                                                    <span className="text-destructive">
                                                        {e.last_error}
                                                    </span>
                                                )}
                                            </span>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        `/settings/ghl-campaigns/enrolments/${e.ulid}/toggle`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {e.active ? 'Pause' : 'Resume'}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {!c.error && (
                                <NewEnrolment c={c} tags={tags} lists={lists} />
                            )}
                        </section>
                    ))
                )}
            </div>
        </>
    );
}
