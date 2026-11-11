import { Button } from '@/components/ui/button';

const labels: Record<string, string> = {
    google: 'Google',
    microsoft: 'Microsoft',
};

/** "Continue with Google / Microsoft" (shown only for providers set up for this deployment). */
export function SsoButtons({ providers }: { providers: string[] }) {
    if (providers.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-2">
            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                <span className="h-px flex-1 bg-border" /> or{' '}
                <span className="h-px flex-1 bg-border" />
            </div>
            {providers.map((p) => (
                <Button key={p} variant="outline" asChild>
                    <a href={`/auth/${p}/start`}>
                        Continue with {labels[p] ?? p}
                    </a>
                </Button>
            ))}
            {providers.includes('microsoft') && (
                <p className="text-center text-xs text-muted-foreground">
                    First time with Microsoft? Sign in with your password, then
                    connect Microsoft in Settings › Security.
                </p>
            )}
        </div>
    );
}
