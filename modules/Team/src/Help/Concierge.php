<?php

declare(strict_types=1);

namespace Modules\Team\Help;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SX6: concierge setup — "send us your spreadsheet". The person sends their list of past clients, contacts or
 * listings as it is (CSV or Excel); RightAlly support sets it up for them (through the brokerage's support access, so
 * the brokerage's "ask first" rule still applies) and answers in the Support queue.
 *
 * The file is stored encrypted, can be downloaded only by HQ (every download audited), and is deleted 30 days after it
 * was sent (team:concierge-cleanup, daily), or when HQ marks the request done.
 */
final class Concierge
{
    public const DAYS = 30;

    public function __construct(private readonly SupportTickets $tickets) {}

    public function send(User $by, UploadedFile $file, string $what, ?string $note): string
    {
        $what = ['contacts' => 'my contacts and past clients', 'listings' => 'my listings', 'other' => 'something else'][$what] ?? 'my contacts and past clients';
        $ulid = $this->tickets->open($by, 'concierge', 'Please set up '.$what.' for me', 'File: '.$file->getClientOriginalName()."\nWhat: {$what}\n".(trim((string) $note) !== '' ? 'Note: '.trim((string) $note) : 'Note: none'), null);
        $path = 'concierge/'.$ulid.'.bin';
        Storage::disk('local')->put($path, Crypt::encryptString(base64_encode((string) $file->get())));
        DB::connection(Connections::control())->table('support_tickets')->where('ulid', $ulid)
            ->update(['file_path' => $path, 'file_name' => mb_substr(Str::of($file->getClientOriginalName())->replaceMatches('/[^\w.\- ]/', '')->toString() ?: 'list.csv', 0, 160)]);

        return $ulid;
    }

    /** HQ: download the file (audited). */
    public static function download(string $ulid, User $by): StreamedResponse
    {
        $t = DB::connection(Connections::control())->table('support_tickets')->where('ulid', $ulid)->whereNotNull('file_path')->first();
        abort_if($t === null || ! Storage::disk('local')->exists((string) $t->file_path), 404);
        app(AuditLogger::class)->record('team.concierge.downloaded', metadata: ['ticket' => $ulid, 'by' => $by->id], severity: 'notice', tenantId: (int) $t->tenant_id);
        $bytes = base64_decode(Crypt::decryptString((string) Storage::disk('local')->get((string) $t->file_path)), true);

        return response()->streamDownload(function () use ($bytes): void {
            echo $bytes;
        }, (string) $t->file_name, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store']);
    }

    /** Delete files older than DAYS days (and any whose request is closed). */
    public static function cleanup(): int
    {
        $db = DB::connection(Connections::control())->table('support_tickets');
        $n = 0;
        foreach ($db->clone()->whereNotNull('file_path')->where(fn ($q) => $q->where('created_at', '<', now()->subDays(self::DAYS))->orWhere('status', 'closed'))->get(['id', 'file_path']) as $t) {
            Storage::disk('local')->delete((string) $t->file_path);
            $db->clone()->where('id', $t->id)->update(['file_path' => null, 'updated_at' => now()]);
            $n++;
        }

        return $n;
    }
}
