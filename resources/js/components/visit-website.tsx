import { usePage } from '@inertiajs/react';
import { GlobeIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';

/**
 * WS1: "Visit website" in the top bar, next to Help: opens the brokerage's live website in a new tab, so owners do
 * not mix it up with the back office. Hidden in HQ and until the website is live.
 */
export function VisitWebsite() {
    const { tenant } = usePage().props;
    const website = tenant?.isHq ? null : (tenant?.website ?? null);

    if (!website) {
        return null;
    }

    return (
        <Button variant="ghost" asChild>
            <a
                href={website}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Visit website (opens in a new tab)"
                title={website.replace(/^https:\/\//, '')}
            >
                <GlobeIcon aria-hidden="true" />
                <span className="hidden sm:inline">Visit website</span>
            </a>
        </Button>
    );
}
