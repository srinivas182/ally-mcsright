import { router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type Stream = {
    ulid: string;
    type: string;
    name: string;
    status: string;
    last_success_at: string | null;
    last_error: string | null;
    failing_since: string | null;
    behind: number;
};

const fields: Record<string, [string, string][]> = {
    webhook: [
        ['url', 'https://siem.example.com/rightally'],
        ['secret', 'Signing secret (24+ characters)'],
    ],
    s3: [
        ['bucket', 'Bucket'],
        ['region', 'us-east-1'],
        ['prefix', 'rightally-audit (optional)'],
        ['key', 'Access key ID'],
        ['secret', 'Secret access key'],
    ],
    datadog: [
        ['api_key', 'Datadog API key'],
        ['site', 'datadoghq.com (optional)'],
    ],
    splunk: [
        ['url', 'https://splunk.example.com:8088'],
        ['token', 'HEC token'],
    ],
};
const labels: Record<string, string> = {
    webhook: 'Webhook',
    s3: 'Amazon S3',
    datadog: 'Datadog',
    splunk: 'Splunk',
};

/** Stream audit events to the brokerage's own security tools. */
export function AuditStreams({ streams }: { streams: Stream[] }) {
    const form = useForm<{
        type: string;
        name: string;
        config: Record<string, string>;
    }>({ type: 'webhook', name: '', config: {} });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <details className="rounded-lg border p-4 text-sm">
            <summary className="cursor-pointer font-medium">
                Streaming to your security tools
            </summary>
            <p className="mt-2 text-muted-foreground">
                New events are sent every minute, in order, each with a unique
                ID. Changes need you to confirm your password.
            </p>
            <ul className="mt-2 divide-y rounded-md border">
                {streams.length === 0 && (
                    <li className="p-2 text-muted-foreground">
                        No destinations yet.
                    </li>
                )}
                {streams.map((s) => (
                    <li
                        key={s.ulid}
                        className="flex flex-wrap items-center justify-between gap-2 p-2"
                    >
                        <span className="grid">
                            <span className="flex items-center gap-2 font-medium">
                                {s.name}{' '}
                                <Badge variant="outline">
                                    {labels[s.type] ?? s.type}
                                </Badge>
                                {s.status === 'paused' ? (
                                    <Badge variant="destructive">Paused</Badge>
                                ) : s.failing_since ? (
                                    <Badge variant="destructive">Failing</Badge>
                                ) : (
                                    <Badge variant="secondary">Healthy</Badge>
                                )}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {s.last_success_at
                                    ? `Last delivered ${new Date(s.last_success_at).toLocaleString()}`
                                    : 'Nothing delivered yet'}
                                {` · ${s.behind} waiting`}
                                {s.last_error ? ` · ${s.last_error}` : ''}
                            </span>
                        </span>
                        <span className="flex gap-1">
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.post(
                                        `/audit/streams/${s.ulid}/test`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Send test
                            </Button>
                            {s.status === 'paused' && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            `/audit/streams/${s.ulid}/resume`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Resume
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(`/audit/streams/${s.ulid}`, {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                Remove
                            </Button>
                        </span>
                    </li>
                ))}
            </ul>
            <form
                className="mt-3 grid gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/audit/streams', {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <div className="flex flex-wrap gap-2">
                    <select
                        aria-label="Destination type"
                        className="rounded border bg-background px-2 py-1.5"
                        value={form.data.type}
                        onChange={(e) =>
                            form.setData({
                                ...form.data,
                                type: e.target.value,
                                config: {},
                            })
                        }
                    >
                        {Object.keys(fields).map((t) => (
                            <option key={t} value={t}>
                                {labels[t]}
                            </option>
                        ))}
                    </select>
                    <Input
                        aria-label="Name"
                        placeholder="Our SIEM"
                        className="max-w-xs"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                </div>
                {fields[form.data.type].map(([key, placeholder]) => (
                    <div key={key}>
                        <Input
                            aria-label={key}
                            placeholder={placeholder}
                            type={
                                ['secret', 'token', 'api_key'].includes(key)
                                    ? 'password'
                                    : 'text'
                            }
                            autoComplete="off"
                            value={form.data.config[key] ?? ''}
                            onChange={(e) =>
                                form.setData('config', {
                                    ...form.data.config,
                                    [key]: e.target.value,
                                })
                            }
                        />
                        {errors[`config.${key}`] && (
                            <p className="text-xs text-destructive">
                                {errors[`config.${key}`]}
                            </p>
                        )}
                    </div>
                ))}
                <Button
                    type="submit"
                    variant="outline"
                    className="justify-self-start"
                    disabled={form.processing}
                >
                    Add destination
                </Button>
            </form>
        </details>
    );
}
