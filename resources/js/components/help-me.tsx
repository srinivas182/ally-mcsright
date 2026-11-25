import { router, usePage } from '@inertiajs/react';
import {
    LifeBuoyIcon,
    PhoneCallIcon,
    PlayCircleIcon,
    PrinterIcon,
    UploadIcon,
    UsersIcon,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { getJson } from '@/lib/http';

type PageHelp = {
    article: { slug: string; title: string; summary: string } | null;
    video: { title: string; url: string; kind: 'embed' | 'file' } | null;
    phone: string | null;
    practice: boolean;
};

/**
 * SX5: "Help" on every page — for this page: a 60-second video (when HQ has added one), the how-to and a printable
 * cheat sheet, "call me back", and practice with made-up clients.
 */
export function HelpMe({ inHeader = false }: { inHeader?: boolean }) {
    const page = usePage();
    // UI3/MN3: Help is a quiet button in the top bar, next to Search; only the simple view keeps the big round one.
    const inTopBar = page.props.simple?.mode !== 'simple';
    const [open, setOpen] = useState(false);
    const [help, setHelp] = useState<PageHelp | null>(null);
    const [watching, setWatching] = useState(false);
    const [calling, setCalling] = useState(false);
    const [phone, setPhone] = useState('');
    const [when, setWhen] = useState('any');
    const [sending, setSending] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const [what, setWhat] = useState('contacts');

    const show = () => {
        setOpen(true);
        setWatching(false);
        setCalling(false);
        setSending(false);
        getJson<PageHelp>(
            `/help/page?path=${encodeURIComponent(page.url.split('?')[0] ?? '/')}`,
        )
            .then((h) => {
                setHelp(h);
                setPhone(h.phone ?? '');
            })
            .catch(() => setHelp(null));
    };

    const big = 'h-16 w-full justify-start gap-3 text-lg';

    // HQ4: "Help" asks RightAlly HQ for help, so it is not shown inside HQ; HQ uses each menu's guide.
    if (inTopBar !== inHeader || page.props.tenant?.isHq === true) {
        return null;
    }

    return (
        <>
            {inHeader ? (
                <Button variant="ghost" onClick={show} aria-label="Help">
                    <LifeBuoyIcon aria-hidden="true" />
                    <span className="hidden sm:inline">Help</span>
                </Button>
            ) : (
                <Button
                    variant="outline"
                    size="lg"
                    className="fixed bottom-40 left-4 z-40 h-14 rounded-full bg-background px-5 text-base shadow-lg md:right-4 md:bottom-[8.75rem] md:left-auto"
                    onClick={show}
                >
                    <LifeBuoyIcon className="size-6" aria-hidden="true" /> Help
                </Button>
            )}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle className="text-2xl">
                            How can we help?
                        </DialogTitle>
                        <DialogDescription className="text-base">
                            {help?.article
                                ? `About this page: ${help.article.summary}`
                                : 'Pick one. You can come back here from any page.'}
                        </DialogDescription>
                    </DialogHeader>

                    {watching && help?.video ? (
                        <div className="grid gap-3">
                            <div className="aspect-video w-full overflow-hidden rounded-lg bg-black">
                                {help.video.kind === 'file' ? (
                                    <video
                                        src={help.video.url}
                                        controls
                                        autoPlay
                                        className="size-full"
                                    >
                                        {/* Captions sit next to the video: same address, ending .vtt. */}
                                        <track
                                            kind="captions"
                                            src={help.video.url.replace(
                                                /\.mp4$/i,
                                                '.vtt',
                                            )}
                                            srcLang="en"
                                            label="English"
                                            default
                                        />
                                    </video>
                                ) : (
                                    <iframe
                                        src={help.video.url}
                                        title={help.video.title}
                                        allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                                        className="size-full"
                                    />
                                )}
                            </div>
                            <Button
                                variant="outline"
                                size="lg"
                                onClick={() => setWatching(false)}
                            >
                                Back
                            </Button>
                        </div>
                    ) : sending ? (
                        <form
                            className="grid gap-3 text-base"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (!file) {
                                    return;
                                }
                                router.post(
                                    '/help/concierge',
                                    { file, what },
                                    {
                                        forceFormData: true,
                                        preserveScroll: true,
                                        onSuccess: () => setOpen(false),
                                    },
                                );
                            }}
                        >
                            <p>
                                Send us your list as it is (a spreadsheet or CSV
                                file). We will set it up for you and email you
                                when it is done.
                            </p>
                            <label className="grid gap-1">
                                What is in it?
                                <select
                                    className="h-12 rounded-md border bg-background px-3 text-lg"
                                    value={what}
                                    onChange={(e) => setWhat(e.target.value)}
                                >
                                    <option value="contacts">
                                        My contacts and past clients
                                    </option>
                                    <option value="listings">
                                        My listings
                                    </option>
                                    <option value="other">
                                        Something else
                                    </option>
                                </select>
                            </label>
                            <label className="grid gap-1">
                                Your file
                                <input
                                    type="file"
                                    accept=".csv,.xlsx,.xls,.txt"
                                    className="text-base"
                                    onChange={(e) =>
                                        setFile(e.target.files?.[0] ?? null)
                                    }
                                    required
                                />
                            </label>
                            <Button
                                type="submit"
                                size="lg"
                                className="h-14 text-lg"
                                disabled={!file}
                            >
                                Send it to RightAlly
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="lg"
                                onClick={() => setSending(false)}
                            >
                                Back
                            </Button>
                        </form>
                    ) : calling ? (
                        <form
                            className="grid gap-3 text-base"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.post(
                                    '/help/callback',
                                    { phone, when, page: page.url },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setOpen(false),
                                    },
                                );
                            }}
                        >
                            <label className="grid gap-1">
                                Your phone number
                                <input
                                    className="h-12 rounded-md border bg-background px-3 text-lg"
                                    inputMode="tel"
                                    autoComplete="tel"
                                    value={phone}
                                    onChange={(e) => setPhone(e.target.value)}
                                    required
                                />
                            </label>
                            <fieldset className="grid gap-2">
                                <legend>When is best?</legend>
                                {[
                                    ['morning', 'Morning'],
                                    ['afternoon', 'Afternoon'],
                                    ['evening', 'Early evening'],
                                    ['any', 'Any time'],
                                ].map(([value, text]) => (
                                    <label
                                        key={value}
                                        className="flex items-center gap-2"
                                    >
                                        <input
                                            type="radio"
                                            name="when"
                                            className="size-5"
                                            checked={when === value}
                                            onChange={() => setWhen(value)}
                                        />
                                        {text}
                                    </label>
                                ))}
                            </fieldset>
                            <Button
                                type="submit"
                                size="lg"
                                className="h-14 text-lg"
                            >
                                Call me back
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="lg"
                                onClick={() => setCalling(false)}
                            >
                                Back
                            </Button>
                        </form>
                    ) : (
                        <div className="grid gap-3">
                            {help?.video && (
                                <Button
                                    variant="outline"
                                    className={big}
                                    onClick={() => setWatching(true)}
                                >
                                    <PlayCircleIcon
                                        className="size-7"
                                        aria-hidden="true"
                                    />
                                    Watch a 1-minute video
                                </Button>
                            )}
                            {help?.article && (
                                <Button
                                    variant="outline"
                                    className={big}
                                    asChild
                                >
                                    <a
                                        href={`/help/${help.article.slug}/print`}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <PrinterIcon
                                            className="size-7"
                                            aria-hidden="true"
                                        />
                                        Print a cheat sheet
                                    </a>
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                className={big}
                                onClick={() => setCalling(true)}
                            >
                                <PhoneCallIcon
                                    className="size-7"
                                    aria-hidden="true"
                                />
                                Call me back
                            </Button>
                            <Button
                                variant="outline"
                                className={big}
                                onClick={() => setSending(true)}
                            >
                                <UploadIcon
                                    className="size-7"
                                    aria-hidden="true"
                                />
                                Send us your spreadsheet
                            </Button>
                            <Button
                                variant="outline"
                                className={big}
                                onClick={() =>
                                    router.post(
                                        help?.practice
                                            ? '/practice/clear'
                                            : '/practice',
                                        {},
                                        { onSuccess: () => setOpen(false) },
                                    )
                                }
                            >
                                <UsersIcon
                                    className="size-7"
                                    aria-hidden="true"
                                />
                                {help?.practice
                                    ? 'Remove my practice people'
                                    : 'Practice with made-up clients'}
                            </Button>
                            <Button
                                variant="ghost"
                                className="h-12 text-base"
                                asChild
                            >
                                <a href="/help">See all how-tos</a>
                            </Button>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
