import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type Agreement = {
    id: number;
    kind: string;
    version: number;
    title: string;
    body: string;
};

/** Read and e-sign an agreement by typing your full name. */
export function SignAgreement({
    agreement,
    name,
}: {
    agreement: Agreement;
    name: string;
}) {
    const form = useForm({ typed_name: '', agree: false });

    return (
        <section className="grid gap-2 rounded-lg border border-primary/40 p-4 text-sm">
            <h2 className="font-semibold">
                {agreement.title} (version {agreement.version})
            </h2>
            <div
                className="max-h-64 overflow-y-auto rounded border bg-muted/30 p-3 whitespace-pre-wrap"
                tabIndex={0}
            >
                {agreement.body}
            </div>
            <form
                className="flex flex-wrap items-center gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/roster/agreements/${agreement.kind}/sign`, {
                        preserveScroll: true,
                    });
                }}
            >
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={form.data.agree}
                        onChange={(e) =>
                            form.setData('agree', e.target.checked)
                        }
                    />{' '}
                    I have read and agree
                </label>
                <Input
                    aria-label="Type your full name to sign"
                    placeholder={`Type “${name}” to sign`}
                    className="w-64"
                    value={form.data.typed_name}
                    onChange={(e) => form.setData('typed_name', e.target.value)}
                />
                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        !form.data.agree || form.data.typed_name.length < 3
                    }
                >
                    Sign
                </Button>
                {Object.values(form.errors)[0] && (
                    <p role="alert" className="w-full text-destructive">
                        {Object.values(form.errors)[0]}
                    </p>
                )}
            </form>
        </section>
    );
}
