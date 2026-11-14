export type AiNotes = { score: number; warnings: string[] } | null;

/** What the AI checks changed in a draft (removed unverified figures or Fair Housing risk wording). */
export function AiNotesPanel({ notes }: { notes: AiNotes }) {
    if (!notes) {
        return null;
    }

    return (
        <div className="grid gap-1 rounded-lg border border-dashed p-3 text-sm">
            <p className="font-medium">
                Written with AI: score {notes.score}. Review before publishing.
            </p>
            {notes.warnings.length === 0 ? (
                <p className="text-muted-foreground">
                    Every figure came from your MLS data or your brief, and no
                    Fair Housing risk wording was found.
                </p>
            ) : (
                <ul className="grid gap-1 text-muted-foreground">
                    {notes.warnings.map((w) => (
                        <li key={w}>• {w}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export type AssistResult<T> = { result: T; warnings: string[] };
