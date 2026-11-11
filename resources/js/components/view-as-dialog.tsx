import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';

/** Ask for the reason before viewing someone's account. */
export function ViewAsDialog({
    name,
    url,
    onClose,
    extra = {},
}: {
    name: string;
    url: string;
    onClose: () => void;
    extra?: Record<string, string>;
}) {
    const form = useForm({ reason: '', ...extra });

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>View as {name}</DialogTitle>
                    <DialogDescription>
                        For up to 60 minutes. They will get an email with your
                        reason. Passwords, security, email and payouts stay
                        locked.
                    </DialogDescription>
                </DialogHeader>
                <form
                    id="view-as"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(url);
                    }}
                >
                    <Textarea
                        aria-label="Reason"
                        placeholder="e.g. Helping with the missing commission on 14 Oak St"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                    {(form.errors.reason ||
                        (form.errors as Record<string, string | undefined>)
                            .user ||
                        (form.errors as Record<string, string | undefined>)
                            .email) && (
                        <p className="mt-1 text-sm text-destructive">
                            {form.errors.reason ??
                                (
                                    form.errors as Record<
                                        string,
                                        string | undefined
                                    >
                                ).user ??
                                (
                                    form.errors as Record<
                                        string,
                                        string | undefined
                                    >
                                ).email}
                        </p>
                    )}
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        form="view-as"
                        disabled={form.processing}
                    >
                        View account
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
