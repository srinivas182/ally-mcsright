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
            'version' => $version, 'via' => in_array($via, ['upload', 'photo', 'email', 'esign'], true) ? $via : 'upload', 'uploaded_by' => $by->id,
        ]);
        $item?->update(['status' => 'submitted', 'submitted_at' => now(), 'fix_reason' => null]);
        if ($item !== null && $item->getAttribute('assigned_reviewer_id') === null) {
            app(Reviews::class)->assign($item);
        }
        app(AuditLogger::class)->record('transactions.document.uploaded', $deal, metadata: ['item' => $item?->label, 'filename' => $doc->filename, 'version' => $version], label: $deal->street);

        return $doc;
    }
}
