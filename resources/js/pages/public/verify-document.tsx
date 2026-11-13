import { Head, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';

type Props = {
    result: {
        valid: boolean;
        title?: string;
        completed_at?: string;
        signers?: string[];
    } | null;
};

/** Anyone can check a signed copy is exactly what was signed. */
export default function VerifyDocument({ result }: Props) {
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <>
            <Head title="Verify a signed document" />
            <main className="mx-auto grid max-w-xl gap-4 p-6 text-sm">
                <h1 className="text-xl font-semibold">
                    Verify a signed document
                </h1>
                <p>
                    Upload a signed PDF to check it is exactly the document that
                    was signed — not changed in any way.
                </p>
                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/verify-document', { forceFormData: true });
                    }}
                >
                    <input
                        aria-label="Signed PDF"
                        type="file"
                        accept="application/pdf"
                        onChange={(e) =>
                            form.setData('file', e.target.files?.[0] ?? null)
                        }
                    />
                    <Button type="submit" disabled={!form.data.file}>
                        Check
                    </Button>
                </form>
                {result &&
                    (result.valid ? (
                        <p
                            role="status"
                            className="rounded-md bg-emerald-50 p-3 text-emerald-900"
                        >
                            Genuine and unchanged: “{result.title}”, completed{' '}
                            {result.completed_at} UTC, signed by{' '}
                            {result.signers?.join(', ')}.
                        </p>
                    ) : (
                        <p
                            role="status"
                            className="rounded-md bg-red-50 p-3 text-red-900"
                        >
                            Not verified: this is not a document we sealed, or
                            it has been changed since signing.
                        </p>
                    ))}
            </main>
        </>
    );
}
