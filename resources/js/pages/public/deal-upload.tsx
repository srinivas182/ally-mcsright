import { Head, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';

type Props = {
    valid: boolean;
    what: string | null;
    name: string | null;
    street: string | null;
    agent: string | null;
    mimes: string[];
    uploaded: boolean;
};

/** The other side's single-use upload page (specs/ai/02, §7.4). */
export default function DealUpload({
    valid,
    what,
    name,
    street,
    agent,
    mimes,
    uploaded,
}: Props) {
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <>
            <Head title="Upload a document" />
            <main className="mx-auto grid max-w-lg gap-4 p-6 text-sm">
                <h1 className="text-xl font-semibold">Upload a document</h1>
                {uploaded ? (
                    <p role="status">
                        Thank you. The agent has it now. You can close this
                        page.
                    </p>
                ) : !valid ? (
                    <p>
                        This link has been used or has expired. Please ask the
                        agent for a new one.
                    </p>
                ) : (
                    <>
                        <p>
                            Hello {name}. {agent} needs <strong>{what}</strong>{' '}
                            for {street}.
                        </p>
                        <form
                            className="grid gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(window.location.pathname, {
                                    forceFormData: true,
                                });
                            }}
                        >
                            <label className="grid gap-1">
                                <span>
                                    The file (PDF, photo or Word, up to 25 MB)
                                </span>
                                <input
                                    type="file"
                                    accept={mimes.join(',')}
                                    onChange={(e) =>
                                        form.setData(
                                            'file',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                            </label>
                            {form.errors.file && (
                                <p role="alert" className="text-destructive">
                                    {form.errors.file}
                                </p>
                            )}
                            <Button
                                type="submit"
                                disabled={
                                    form.data.file === null || form.processing
                                }
                            >
                                Upload
                            </Button>
                        </form>
                        <p className="text-muted-foreground">
                            This link works once. We will never ask you to send
                            money or change wiring instructions by email.
                        </p>
                    </>
                )}
            </main>
        </>
    );
}
