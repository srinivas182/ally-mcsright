import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

/** MK2: the email signature from the brand kit, added to RightAlly emails and ready to paste into Gmail or Outlook. */
export default function Signature({
    text,
    html,
}: {
    text: string;
    html: string;
}) {
    const [copied, setCopied] = useState<string | null>(null);
    const copyRich = async () => {
        try {
            await navigator.clipboard.write([
                new ClipboardItem({
                    'text/html': new Blob([html], { type: 'text/html' }),
                    'text/plain': new Blob([text], { type: 'text/plain' }),
                }),
            ]);
        } catch {
            await navigator.clipboard?.writeText(html);
        }
        setCopied('rich');
    };

    return (
        <>
            <Head title="Email signature" />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Email signature"
                    description="Made from your brand kit. It is added to every email you send from RightAlly. Copy it into Gmail or Outlook too, so every email looks the same."
                />
                <section className="grid gap-3 rounded-lg border p-4">
                    <h2 className="font-semibold">How it looks</h2>
                    <div
                        className="rounded-md border bg-white p-4"
                        // Built on the server from the brand kit with every value escaped.
                        dangerouslySetInnerHTML={{ __html: html }}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={() => void copyRich()}>
                            {copied === 'rich'
                                ? 'Copied. Paste it into Gmail or Outlook settings'
                                : 'Copy signature'}
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => {
                                void navigator.clipboard
                                    ?.writeText(html)
                                    .then(() => setCopied('html'));
                            }}
                        >
                            {copied === 'html' ? 'Copied' : 'Copy as HTML'}
                        </Button>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Gmail: Settings, See all settings, Signature, paste.
                        Outlook: Settings, Mail, Compose and reply, paste.
                    </p>
                </section>
                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="font-semibold">In RightAlly emails</h2>
                    <pre className="rounded-md bg-muted p-3 text-sm whitespace-pre-wrap">
                        {text}
                    </pre>
                    <p className="text-sm text-muted-foreground">
                        To change it, update your photo, phone or title in Ally
                        Studio&rsquo;s brand kit.
                    </p>
                </section>
            </div>
        </>
    );
}
