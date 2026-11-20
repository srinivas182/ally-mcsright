/**
 * OB1 (specs/core/20): tells Watchtower when something crashes in the browser, so HQ hears about it with the other
 * problems. At most five reports a page, each different; the address is reported as a pattern (ids taken out), and the
 * server removes personal data before anything is kept. HQ can switch browser errors off.
 */
const sent = new Set<string>();
const LIMIT = 5;

/** "/contacts/123/deals/01J…" becomes "/contacts/{id}/deals/{id}". */
export function pagePattern(path: string): string {
    return path
        .split('/')
        .map((part) =>
            /\d/.test(part) || /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(part)
                ? '{id}'
                : part,
        )
        .join('/')
        .slice(0, 120);
}

export function reportBrowserError(
    message: string,
    stack: string | undefined,
    kind: 'error' | 'rejection' | 'render',
): void {
    const key = `${kind}:${message}`;
    if (sent.size >= LIMIT || sent.has(key) || message.trim() === '') {
        return;
    }
    sent.add(key);
    try {
        void fetch('/watchtower/browser-error', {
            method: 'POST',
            keepalive: true,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                message: message.slice(0, 1000),
                stack: (stack ?? '').slice(0, 8000),
                page: pagePattern(window.location.pathname),
                kind,
            }),
        }).catch(() => undefined);
    } catch {
        // reporting must never cause another error
    }
}

export function watchBrowserErrors(): void {
    window.addEventListener('error', (event) => {
        const error: unknown = event.error;
        reportBrowserError(
            event.message || String(error),
            error instanceof Error ? error.stack : undefined,
            'error',
        );
    });
    window.addEventListener('unhandledrejection', (event) => {
        const reason: unknown = event.reason;
        reportBrowserError(
            reason instanceof Error ? reason.message : String(reason),
            reason instanceof Error ? reason.stack : undefined,
            'rejection',
        );
    });
}
