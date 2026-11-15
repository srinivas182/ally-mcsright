import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';

export type McpPanel = {
    address: string;
    settings: { enabled: boolean; allow_writes: boolean };
    allowed: boolean;
    connected: {
        client: string;
        client_id: string;
        user: string;
        user_ulid: string;
        mine: boolean;
        can_write: boolean;
        last_used: string | null;
        connected: string;
    }[];
    is_owner: boolean;
};

/** Apps → AI assistants: how to connect, who is connected, and the owner's switches (specs/connect/03). */
export function AiAssistantsPanel({ mcp }: { mcp: McpPanel }) {
    const [copied, setCopied] = useState(false);
    const save = (enabled: boolean, allowWrites: boolean) =>
        router.post(
            '/apps/ai_assistants/settings',
            { enabled, allow_writes: allowWrites },
            { preserveScroll: true },
        );

    return (
        <div className="grid gap-4">
            {mcp.allowed ? (
                <section className="grid gap-3 rounded-lg border p-5">
                    <h2 className="font-medium">Connect your AI assistant</h2>
                    <ol className="grid list-decimal gap-2 pl-5">
                        <li>
                            In Claude or ChatGPT, open{' '}
                            <strong>Settings → Connectors</strong> (ChatGPT:{' '}
                            <strong>Apps &amp; Connectors</strong>) and choose
                            to add a custom connector.
                        </li>
                        <li>
                            Paste this address:{' '}
                            <code className="rounded bg-muted px-2 py-1 break-all">
                                {mcp.address}
                            </code>{' '}
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    void navigator.clipboard
                                        .writeText(mcp.address)
                                        .then(() => setCopied(true));
                                }}
                            >
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </li>
                        <li>
                            Sign in to RightAlly when it asks, and press{' '}
                            <strong>Allow</strong>. That's all.
                        </li>
                    </ol>
                    <p className="text-muted-foreground">
                        Then ask things like “Who should I call today?” or “When
                        does the inspection period end on 12 Oak St?”. It sees
                        and changes only what you can in RightAlly.
                    </p>
                </section>
            ) : (
                <p className="rounded-lg border p-5">
                    AI assistant access is switched off for your brokerage.
                </p>
            )}

            {mcp.is_owner && (
                <section className="grid gap-3 rounded-lg border p-5">
                    <h2 className="font-medium">For the brokerage</h2>
                    <label className="flex items-center gap-3">
                        <Switch
                            checked={mcp.settings.enabled}
                            onCheckedChange={(v) =>
                                save(v, mcp.settings.allow_writes)
                            }
                        />
                        <span>
                            Let people connect their AI assistants
                            <span className="block text-muted-foreground">
                                Turning this off disconnects every assistant
                                straight away.
                            </span>
                        </span>
                    </label>
                    <label className="flex items-center gap-3">
                        <Switch
                            checked={mcp.settings.allow_writes}
                            disabled={!mcp.settings.enabled}
                            onCheckedChange={(v) =>
                                save(mcp.settings.enabled, v)
                            }
                        />
                        <span>
                            Let assistants add leads, notes and tasks (each
                            person also approves this for their own)
                        </span>
                    </label>
                </section>
            )}

            <section className="grid gap-3 rounded-lg border p-5">
                <h2 className="font-medium">
                    {mcp.is_owner
                        ? 'Connected assistants'
                        : 'Your connected assistants'}
                </h2>
                {mcp.connected.length === 0 ? (
                    <p className="text-muted-foreground">None yet.</p>
                ) : (
                    <ul className="grid gap-2">
                        {mcp.connected.map((c) => (
                            <li
                                key={c.user_ulid + c.client_id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3"
                            >
                                <span>
                                    <strong>{c.client}</strong>
                                    {mcp.is_owner && !c.mine
                                        ? ` · ${c.user}`
                                        : ''}{' '}
                                    <Badge variant="outline">
                                        {c.can_write
                                            ? 'Can add records'
                                            : 'Read only'}
                                    </Badge>
                                </span>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    onClick={() =>
                                        router.post(
                                            '/apps/ai_assistants/disconnect',
                                            {
                                                user: c.user_ulid,
                                                client: c.client_id,
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Disconnect
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
