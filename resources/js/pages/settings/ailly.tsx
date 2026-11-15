import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { MediaPicker, type MediaItem } from '@/components/sites/media-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

type Settings = {
    enabled: boolean;
    name: string;
    photo: string | null;
    greeting: string;
    gate: 'before' | 'after' | 'booking';
    gate_after: number;
    visitor_daily: number;
    daily_cap: number;
};
type Conversation = {
    ulid: string;
    at: string;
    page: string;
    outcome: 'none' | 'lead' | 'call' | 'showing';
    score: number | null;
    answers: number;
    messages: { role: string; body: string }[];
};
type Props = {
    site: { live: boolean; name: string } | null;
    site_ulid: string | null;
    photo_url: string | null;
    media: MediaItem[];
    settings: Settings;
    month: {
        conversations: number;
        leads: number;
        calls: number;
        answers: number;
        free_answers: number;
    };
    conversations: Conversation[];
};

const outcomeLabel: Record<Conversation['outcome'], string> = {
    none: 'No details yet',
    lead: 'Lead',
    call: 'Call booked',
    showing: 'Showing requested',
};

/** Settings → AIlly assistant: the chat on the brokerage's website (and its agents' websites). */
export default function AillySettings({
    site,
    site_ulid,
    settings,
    month,
    conversations,
    media: initialMedia,
}: Props) {
    const form = useForm({ ...settings });
    const [media, setMedia] = useState(initialMedia);
    const [open, setOpen] = useState<string | null>(null);
    const rate =
        month.conversations > 0
            ? Math.round((month.leads * 100) / month.conversations)
            : 0;

    return (
        <>
            <Head title="AIlly assistant" />
            <div className="flex max-w-4xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="AIlly assistant"
                    description="A chat on your website that answers questions, shows matching homes and turns visitors into leads for you and your agents."
                />
                {site === null && (
                    <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                        Set up your website first (Settings → Website); AIlly
                        appears on it once switched on.
                    </p>
                )}
                <section className="grid gap-3 sm:grid-cols-4">
                    {[
                        ['Chats this month', month.conversations],
                        ['Leads', month.leads],
                        ['Calls booked', month.calls],
                        ['Chats that became leads', `${rate}%`],
                    ].map(([label, value]) => (
                        <div
                            key={String(label)}
                            className="rounded-lg border p-4 text-sm"
                        >
                            <p className="text-muted-foreground">{label}</p>
                            <p className="text-2xl font-semibold">{value}</p>
                        </div>
                    ))}
                </section>
                <p className="text-sm text-muted-foreground">
                    {month.answers.toLocaleString()} AI answers this month (each
                    uses RightAlly credits), plus{' '}
                    {month.free_answers.toLocaleString()} repeated questions
                    answered for free.
                </p>
                <form
                    className="grid gap-4 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/settings/ailly', { preserveScroll: true });
                    }}
                >
                    <label className="flex items-center gap-3">
                        <Switch
                            checked={form.data.enabled}
                            onCheckedChange={(v) => form.setData('enabled', v)}
                        />
                        <span className="font-medium">
                            Show AIlly on our website
                        </span>
                        {settings.enabled ? (
                            <Badge>On</Badge>
                        ) : (
                            <Badge variant="outline">Off</Badge>
                        )}
                    </label>
                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="grid gap-1">
                            Assistant's name
                            <Input
                                value={form.data.name}
                                maxLength={30}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                        </label>
                        {site_ulid && (
                            <div className="grid gap-1">
                                <MediaPicker
                                    label="Profile photo (optional; a friendly illustration is used otherwise)"
                                    value={form.data.photo}
                                    media={media}
                                    site={site_ulid}
                                    onChange={(v) => form.setData('photo', v)}
                                    onUploaded={(m) => setMedia([m, ...media])}
                                />
                            </div>
                        )}
                        <label className="grid gap-1 md:col-span-2">
                            Greeting (optional)
                            <Textarea
                                rows={2}
                                maxLength={240}
                                placeholder={`Hi, I'm ${form.data.name}. I can help you find homes, answer questions about buying or selling, or connect you with an agent.`}
                                value={form.data.greeting}
                                onChange={(e) =>
                                    form.setData('greeting', e.target.value)
                                }
                            />
                        </label>
                    </div>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 font-medium">
                            When should AIlly ask for name, email and phone?
                        </legend>
                        {(
                            [
                                [
                                    'before',
                                    'Before the first answer',
                                    'Most leads per chat, but more visitors leave without asking.',
                                ],
                                [
                                    'after',
                                    'After a few answers (recommended)',
                                    'Visitors see value first, then share details to keep going.',
                                ],
                                [
                                    'booking',
                                    'Only when booking a call or a showing',
                                    'Friendliest; fewer leads, but very warm ones.',
                                ],
                            ] as const
                        ).map(([value, label, hint]) => (
                            <label
                                key={value}
                                className="flex items-start gap-2"
                            >
                                <input
                                    type="radio"
                                    name="gate"
                                    className="mt-1"
                                    checked={form.data.gate === value}
                                    onChange={() => form.setData('gate', value)}
                                />
                                <span>
                                    {label}
                                    {value === 'after' && (
                                        <>
                                            {' '}
                                            (after{' '}
                                            <Input
                                                type="number"
                                                min={1}
                                                max={10}
                                                className="inline-block h-7 w-16"
                                                value={form.data.gate_after}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'gate_after',
                                                        parseInt(
                                                            e.target.value ||
                                                                '1',
                                                            10,
                                                        ),
                                                    )
                                                }
                                            />{' '}
                                            answers)
                                        </>
                                    )}
                                    <span className="block text-muted-foreground">
                                        {hint}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="grid gap-1">
                            Most messages one visitor can send a day
                            <Input
                                type="number"
                                min={5}
                                max={100}
                                value={form.data.visitor_daily}
                                onChange={(e) =>
                                    form.setData(
                                        'visitor_daily',
                                        parseInt(e.target.value || '5', 10),
                                    )
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            Most new chats a day
                            <Input
                                type="number"
                                min={10}
                                max={10000}
                                value={form.data.daily_cap}
                                onChange={(e) =>
                                    form.setData(
                                        'daily_cap',
                                        parseInt(e.target.value || '10', 10),
                                    )
                                }
                            />
                        </label>
                    </div>
                    <p className="text-muted-foreground">
                        To keep credits for real people, AIlly ignores bots,
                        answers a repeated first question for free, ends chats
                        that aren't about homes, and asks for details instead of
                        answering when credits run out. Every chat that shares
                        details becomes a lead (on an agent's website, that
                        agent's lead).
                    </p>
                    {Object.values(form.errors).map((e) => (
                        <p key={e} className="text-destructive">
                            {e}
                        </p>
                    ))}
                    <div>
                        <Button disabled={form.processing}>Save</Button>
                    </div>
                </form>
                <section className="grid gap-2 text-sm">
                    <h2 className="font-semibold">Latest chats</h2>
                    {conversations.length === 0 && (
                        <p className="text-muted-foreground">No chats yet.</p>
                    )}
                    <ul className="divide-y rounded-lg border">
                        {conversations.map((c) => (
                            <li key={c.ulid} className="grid gap-2 p-3">
                                <button
                                    type="button"
                                    className="flex flex-wrap items-center justify-between gap-2 text-left"
                                    aria-expanded={open === c.ulid}
                                    onClick={() =>
                                        setOpen(open === c.ulid ? null : c.ulid)
                                    }
                                >
                                    <span>
                                        {new Date(c.at).toLocaleString()} ·{' '}
                                        {c.page || '/'} · {c.answers} answers
                                    </span>
                                    <span className="flex items-center gap-2">
                                        {c.score !== null && (
                                            <span className="text-muted-foreground">
                                                Lead score {c.score}
                                            </span>
                                        )}
                                        <Badge
                                            variant={
                                                c.outcome === 'none'
                                                    ? 'outline'
                                                    : 'default'
                                            }
                                        >
                                            {outcomeLabel[c.outcome]}
                                        </Badge>
                                    </span>
                                </button>
                                {open === c.ulid && (
                                    <div className="grid gap-1 rounded-md bg-muted/40 p-3">
                                        {c.messages.map((m, i) => (
                                            <p key={i}>
                                                <strong>
                                                    {m.role === 'visitor'
                                                        ? 'Visitor'
                                                        : 'AIlly'}
                                                    :
                                                </strong>{' '}
                                                {m.body}
                                            </p>
                                        ))}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

AillySettings.layout = {
    breadcrumbs: [{ title: 'AIlly assistant', href: '/settings/ailly' }],
};
