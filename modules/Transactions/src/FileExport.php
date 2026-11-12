<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Audit\AuditEvent;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Audit\AuditPresenter;
use Modules\Core\Audit\AuditVerifier;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\Item;
use RuntimeException;
use ZipArchive;

/**
 * Complete transaction file (specs/transactions/01, addition 7): every document version, the checklist
 * with each review decision, the deal's audit trail, and an audit certificate listing each document's
 * SHA-256 so anyone can prove a file was not changed after export. The download is itself audited.
 */
final class FileExport
{
    public function build(Deal $deal, User $by): string
    {
        $path = tempnam(sys_get_temp_dir(), 'deal-').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export.');
        }

        $certificate = ["Complete transaction file — {$deal->street}", 'Exported '.now()->toIso8601String().' by '.$by->name, '', 'Documents (SHA-256):'];
        foreach (Document::query()->where('deal_id', $deal->id)->orderBy('id')->get() as $doc) {
            $name = sprintf('documents/%03d-v%d-%s', $doc->id, $doc->version, preg_replace('/[^A-Za-z0-9._-]/', '_', $doc->filename));
            $contents = Storage::disk('local')->get($doc->path);
            if ($contents !== null) {
                $zip->addFromString($name, $contents);
            }
            $certificate[] = "{$doc->sha256}  {$name}";
        }

        $items = Item::query()->where('deal_id', $deal->id)->orderBy('sort')->get()->map(fn (Item $i): array => [
            'item' => $i->label, 'required' => (bool) $i->required, 'status' => $i->status, 'fix_reason' => $i->fix_reason,
            'reviewed_by' => $i->reviewed_by !== null ? User::query()->find($i->reviewed_by)?->name : null, 'reviewed_at' => $i->reviewed_at?->toIso8601String(),
        ]);
        $zip->addFromString('checklist.json', (string) json_encode($items, JSON_PRETTY_PRINT));

        $audit = AuditEvent::query()->where('subject_type', 'Deal')->where('subject_id', $deal->ulid)->orderBy('chain_seq')->get()
            ->map(fn (AuditEvent $e): array => AuditPresenter::row($e))->all();
        $zip->addFromString('audit-trail.json', (string) json_encode($audit, JSON_PRETTY_PRINT));
        $verification = app(AuditVerifier::class)->verify($deal->tenant_id);
        $certificate[] = '';
        $certificate[] = 'Audit events for this deal: '.count($audit).'.';
        $certificate[] = 'Audit chain check: '.(string) json_encode($verification);
        $zip->addFromString('audit-certificate.txt', implode("\n", $certificate)."\n");
        $zip->close();

        app(AuditLogger::class)->record('transactions.file.exported', $deal, metadata: ['documents' => Document::query()->where('deal_id', $deal->id)->count()], severity: 'notice', label: $deal->street);

        return $path;
    }
}
