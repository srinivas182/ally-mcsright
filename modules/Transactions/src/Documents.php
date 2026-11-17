<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;
use Modules\Transactions\Models\Item;

/**
 * Deal documents: uploads and phone photos into checklist items, kept with every version.
 */
final class Documents
{
    public const MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    public const MAX_BYTES = 25 * 1024 * 1024;

    public function upload(Deal $deal, ?Item $item, UploadedFile $file, string $via, User $by): Document
    {
        if ($deal->locked_at !== null) {
            throw ValidationException::withMessages(['file' => 'This deal is closed and locked.']);
        }
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::MIMES, true) || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Upload a PDF, photo or Word document up to 25 MB.']);
        }
        if ($item !== null && $item->deal_id !== $deal->id) {
            throw ValidationException::withMessages(['item' => 'That checklist item belongs to another deal.']);
        }
        $path = $file->storeAs("tx/{$deal->tenant_id}/{$deal->ulid}", Str::ulid().'.'.($file->guessExtension() ?? 'bin'), 'local');
        $version = $item !== null ? Document::query()->where('item_id', $item->id)->count() + 1 : 1;
        $doc = Document::query()->create([
            'deal_id' => $deal->id, 'item_id' => $item?->id, 'filename' => mb_substr($file->getClientOriginalName(), 0, 200), 'path' => (string) $path,
            'mime' => $mime, 'size' => (int) $file->getSize(), 'sha256' => hash_file('sha256', Storage::disk('local')->path((string) $path)) ?: '',
            'version' => $version, 'via' => in_array($via, ['upload', 'photo', 'email', 'esign', 'link', 'portal'], true) ? $via : 'upload', 'uploaded_by' => $by->id,
        ]);
        $item?->update(['status' => 'submitted', 'submitted_at' => now(), 'fix_reason' => null]);
        if ($item !== null && $item->getAttribute('assigned_reviewer_id') === null) {
            app(Reviews::class)->assign($item);
        }
        app(AuditLogger::class)->record('transactions.document.uploaded', $deal, metadata: ['item' => $item?->label, 'filename' => $doc->filename, 'version' => $version], label: $deal->street);
        if (Reading\DocIq::readable($mime)) {
            DocumentReading::query()->create(['document_id' => $doc->id, 'status' => 'queued']);
            Jobs\ReadDocument::dispatch($doc->id)->afterCommit(); // reads, then re-scans compliance
        } else {
            app(Compliance\ComplianceScan::class)->run($deal);
        }

        return $doc;
    }

    /**
     * A document brought over from another transaction app (specs/connect/12) or emailed to the deal (LM1, via "email"). It goes into the deal's unsorted
     * documents (the broker files it into the checklist), is not read by AI automatically (that costs credits; it
     * can be read on request), and is added even to a closed, locked deal, because the file is the deal's history.
     * The same file twice in one deal is kept once.
     *
     * @return Document|null null when it is not a PDF or image we keep, is too big, or is already there
     */
    public function imported(Deal $deal, string $filename, string $bytes, string $via = 'import'): ?Document
    {
        $mime = self::sniff($bytes);
        if ($mime === null || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        $sha = hash('sha256', $bytes);
        if (Document::query()->where('deal_id', $deal->id)->where('sha256', $sha)->exists()) {
            return null;
        }
        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime];
        $path = "tx/{$deal->tenant_id}/{$deal->ulid}/".Str::ulid().'.'.$ext;
        Storage::disk('local')->put($path, $bytes);
        $name = trim(preg_replace('/[^\pL\pN ._()\-]+/u', '_', $filename) ?? '');
        $name = $name === '' ? 'document.'.$ext : (str_ends_with(mb_strtolower($name), '.'.$ext) ? $name : $name.'.'.$ext);

        return Document::query()->create(['deal_id' => $deal->id, 'item_id' => null, 'filename' => mb_substr($name, -200), 'path' => $path, 'mime' => $mime,
            'size' => strlen($bytes), 'sha256' => $sha, 'version' => 1, 'via' => $via, 'uploaded_by' => null]);
    }

    /** What the bytes really are (never the other app's word for it). */
    private static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            default => null,
        };
    }
}
