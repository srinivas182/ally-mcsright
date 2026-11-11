import { UploadIcon } from 'lucide-react';
import * as React from 'react';

import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';

type FileUploadProps = {
    /** Upload one file, reporting progress 0–100. */
    onUpload: (file: File, onProgress: (percent: number) => void) => Promise<void>;
    accept?: string;
    maxBytes?: number;
    label?: string;
};

/** Drag and drop or tap to choose; shows progress and friendly errors. */
function FileUpload({ onUpload, accept, maxBytes = 25 * 1024 * 1024, label = 'Upload a file' }: FileUploadProps) {
    const inputId = React.useId();
    const [progress, setProgress] = React.useState<number | null>(null);
    const [error, setError] = React.useState<string | null>(null);
    const [dragging, setDragging] = React.useState(false);

    async function handle(file: File | undefined) {
        if (!file) {
            return;
        }
        if (file.size > maxBytes) {
            setError(`That file is larger than ${Math.round(maxBytes / 1024 / 1024)} MB.`);
            return;
        }
        setError(null);
        setProgress(0);
        try {
            await onUpload(file, setProgress);
            setProgress(100);
        } catch {
            setError('The upload did not finish. Please try again.');
            setProgress(null);
        }
    }

    return (
        <div className="grid gap-2">
            <label
                htmlFor={inputId}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragging(false);
                    void handle(e.dataTransfer.files[0]);
                }}
                className={cn(
                    'flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed p-6 text-center text-sm',
                    dragging && 'border-primary bg-accent',
                )}
            >
                <UploadIcon aria-hidden="true" className="size-6 text-muted-foreground" />
                <span className="font-medium">{label}</span>
                <span className="text-muted-foreground">Drag a file here or tap to choose</span>
            </label>
            <input
                id={inputId}
                type="file"
                accept={accept}
                className="sr-only"
                onChange={(e) => void handle(e.target.files?.[0])}
            />
            {progress !== null && <Progress value={progress} aria-label="Upload progress" />}
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

export { FileUpload };
