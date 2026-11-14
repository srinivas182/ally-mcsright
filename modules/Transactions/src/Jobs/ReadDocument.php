<?php

declare(strict_types=1);

namespace Modules\Transactions\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;
use Modules\Transactions\Reading\DocIq;
use Throwable;

/** Reads a newly uploaded document in the background (DocIQ, specs/ai/03). */
final class ReadDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(public readonly int $documentId) {}

    public function handle(DocIq $docIq): void
    {
        $doc = Document::query()->find($this->documentId);
        if ($doc === null) {
            return;
        }
        try {
            $docIq->read($doc);
        } catch (Throwable $e) {
            DocumentReading::query()->updateOrCreate(['document_id' => $doc->id], ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'read_at' => now()]);
            report($e);
        }
    }
}
