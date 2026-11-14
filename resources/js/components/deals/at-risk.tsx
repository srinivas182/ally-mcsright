import { router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

export type AtRiskItem = {
    deal: string;
    street: string;
    what: string;
    when: string | null;
    level: string;
};
export type AlertPrefs = {
    sms: boolean;
    voice: boolean;
    sms_allowed: boolean;
    voice_allowed: boolean;
    phone: string | null;
    calendar_url: string | null;
};

/** The agent's "At risk today": dates due or missed and open blockers, alert choices and calendar link. */
export function AtRisk({
    items,
    alerts,
}: {
    items: AtRiskItem[];
    alerts: AlertPrefs;
}) {
    const opts = { preserveScroll: true };
    const save = (sms: boolean, voice: boolean) =>
        router.post('/deals/alerts', { sms, voice }, opts);

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <h2 className="font-semibold">At risk today</h2>
            {items.length === 0 ? (
                <p className="text-muted-foreground">
                    Nothing due in the next three days and no blockers on your
                    deals.
                </p>
            ) : (
                <ul className="divide-y rounded border">
                    {items.map((r, i) => (
                        <li
                            key={i}
                            className="flex flex-wrap items-center gap-2 p-2"
                        >
                            <Badge
                                variant={
                                    r.level === 'high'
                                        ? 'destructive'
                                        : 'outline'
                                }
                            >
                                {r.when ?? 'blocker'}
                            </Badge>
                            <span className="min-w-0 flex-1">{r.what}</span>
                            <a className="underline" href={`/deals/${r.deal}`}>
                                {r.street}
                            </a>
                        </li>
                    ))}
                </ul>
            )}
            <div className="grid gap-2 border-t pt-2">
                <p className="text-xs text-muted-foreground">
                    You always get an email digest. You can also be reminded by
                    text the day before and on the day, and by an automated call
                    on the day (8am–9pm only)
                    {alerts.phone ? ` at ${alerts.phone}` : ''}.
                </p>
                <div className="flex flex-wrap gap-4">
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-4"
                            disabled={!alerts.sms_allowed}
                            checked={alerts.sms}
                            onChange={(e) =>
                                save(e.target.checked, alerts.voice)
                            }
                        />
                        Text me about deadlines
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-4"
                            disabled={!alerts.voice_allowed}
                            checked={alerts.voice}
                            onChange={(e) => save(alerts.sms, e.target.checked)}
                        />
                        Call me on the due day
                    </label>
                </div>
                {(!alerts.sms_allowed || !alerts.voice_allowed) && (
                    <p className="text-xs text-muted-foreground">
                        {!alerts.sms_allowed && !alerts.voice_allowed
                            ? 'Texts and calls'
                            : !alerts.sms_allowed
                              ? 'Texts'
                              : 'Calls'}{' '}
                        are not switched on for your brokerage yet.
                    </p>
                )}
                {!alerts.phone && (
                    <p className="text-xs text-muted-foreground">
                        Add your mobile number to your profile to get texts or
                        calls.
                    </p>
                )}
                <span className="flex flex-wrap items-center gap-2">
                    {alerts.calendar_url ? (
                        <>
                            <span className="text-xs">Your calendar link:</span>
                            <code className="max-w-full truncate rounded bg-muted px-1 text-xs">
                                {alerts.calendar_url}
                            </code>
                        </>
                    ) : (
                        <span className="text-xs text-muted-foreground">
                            See every key date in Google, Outlook or Apple
                            Calendar.
                        </span>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => router.post('/deals/calendar', {}, opts)}
                    >
                        {alerts.calendar_url
                            ? 'New link'
                            : 'Get my calendar link'}
                    </Button>
                </span>
            </div>
        </section>
    );
}
