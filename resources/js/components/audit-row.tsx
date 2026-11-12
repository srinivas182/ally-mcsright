import { useState } from 'react';

import { Badge } from '@/components/ui/badge';

export type AuditRow = {
    ulid: string;
    at: string;
    who: string;
    what: string;
    subject: string | null;
    subject_type: string | null;
    severity: string;
    fields: string[];
    changes: Record<string, { old: unknown; new: unknown }> | null;
    metadata: Record<string, unknown> | null;
    ip: string | null;
    action: string;
    seq: number;
};

const show = (v: unknown): string => {
    if (v === null || v === undefined || v === '') {
        return '—';
    }
    if (
        typeof v === 'string' ||
        typeof v === 'number' ||
        typeof v === 'boolean'
    ) {
        return String(v);
    }
    return JSON.stringify(v);
};

/** One audit event: a readable line, details on tap. */
export function AuditEventRow({ e }: { e: AuditRow }) {
    const [open, setOpen] = useState(false);

    return (
        <li className="p-3 text-sm">
            <button
                type="button"
                className="grid w-full gap-0.5 text-left"
                aria-expanded={open}
                onClick={() => setOpen(!open)}
            >
                <span className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{e.who}</span>
                    <span>{e.what}</span>
                    {e.subject && (
                        <span className="font-medium">{e.subject}</span>
                    )}
                    {e.severity !== 'info' && (
                        <Badge
                            variant={
                                e.severity === 'critical' ||
                                e.severity === 'warning'
                                    ? 'destructive'
                                    : 'secondary'
                            }
                        >
                            {e.severity}
                        </Badge>
                    )}
                </span>
                <span className="text-xs text-muted-foreground">
                    {new Date(e.at).toLocaleString()}
                    {e.fields.length > 0 && ` · ${e.fields.join(', ')}`}
                </span>
            </button>
            {open && (
                <div className="mt-2 grid gap-1 rounded-md bg-muted/40 p-2 text-xs">
                    {e.changes &&
                        Object.entries(e.changes).map(([field, c]) => (
                            <p key={field}>
                                <span className="font-medium">{field}</span>:{' '}
                                {show(c.old)} → {show(c.new)}
                            </p>
                        ))}
                    {e.metadata &&
                        Object.entries(e.metadata).map(([k, v]) => (
                            <p key={k}>
                                <span className="font-medium">{k}</span>:{' '}
                                {show(v)}
                            </p>
                        ))}
                    <p className="text-muted-foreground">
                        {e.action} · #{e.seq}
                        {e.ip ? ` · ${e.ip}` : ''}
                    </p>
                </div>
            )}
        </li>
    );
}
