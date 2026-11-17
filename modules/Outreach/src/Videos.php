<?php

declare(strict_types=1);

namespace Modules\Outreach;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Contact;
use Modules\Sites\SiteSetup;

/**
 * Video messages (MK1, specs/crm/20): an agent records a short video in the browser (or uploads one) and shares a
 * private link on the brokerage's website. The page is never indexed or cached; the agent sees when it is watched,
 * and a first view goes on the contact's timeline. Files are kept privately, never on a public URL.
 */
final class Videos
{
    public const MAX_BYTES = 150 * 1024 * 1024;

    public const TYPES = ['video/webm' => 'webm', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov'];

    public function __construct(private readonly TenantContext $tenants) {}

    public function store(User $user, UploadedFile $file, string $title, ?string $message, ?Contact $contact): string
    {
        $mime = self::sniff($file);
        if ($mime === null || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['video' => 'Use a video (WebM, MP4 or MOV) under 150 MB, about 3 minutes.']);
        }
        $ulid = (string) Str::ulid();
        $path = "outreach/{$this->tenants->id()}/videos/{$ulid}.".self::TYPES[$mime];
        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));
        DB::connection(Connections::cell())->table('outreach_videos')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'user_id' => $user->id,
            'contact_id' => $contact?->id, 'title' => mb_substr(trim($title), 0, 120) ?: 'A video for you', 'message' => $message !== null ? mb_substr(trim($message), 0, 500) : null,
            'path' => $path, 'mime' => $mime, 'size' => (int) $file->getSize(), 'token' => Str::random(48), 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('outreach.video_added', metadata: ['video' => $ulid, 'contact' => $contact?->id]);

        return $ulid;
    }

    /**
     * @return list<array{ulid: string, title: string, message: string|null, contact: string|null, url: string|null, views: int, first_viewed_at: string|null, last_viewed_at: string|null, created_at: string}>
     */
    public function forUser(User $user): array
    {
        $host = app(SiteSetup::class)->liveHost();
        $rows = DB::connection(Connections::cell())->table('outreach_videos')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->orderByDesc('id')->limit(100)->get();
        $names = Contact::query()->whereIn('id', $rows->pluck('contact_id')->filter()->all() ?: [0])->get()->keyBy('id');

        return array_values($rows->map(fn ($r): array => ['ulid' => (string) $r->ulid, 'title' => (string) $r->title, 'message' => $r->message !== null ? (string) $r->message : null,
            'contact' => ($c = $r->contact_id !== null ? $names->get($r->contact_id) : null) instanceof Contact ? trim($c->first_name.' '.$c->last_name) : null,
            'url' => $host !== null ? 'https://'.$host.'/v/'.$r->token : null, 'views' => (int) $r->views,
            'first_viewed_at' => $r->first_viewed_at !== null ? (string) $r->first_viewed_at : null, 'last_viewed_at' => $r->last_viewed_at !== null ? (string) $r->last_viewed_at : null,
            'created_at' => (string) $r->created_at])->all());
    }

    public function remove(User $user, string $ulid): void
    {
        $row = DB::connection(Connections::cell())->table('outreach_videos')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->where('ulid', $ulid)->first()
            ?? abort(404);
        Storage::disk('local')->delete((string) $row->path);
        DB::connection(Connections::cell())->table('outreach_videos')->where('id', $row->id)->delete();
        app(AuditLogger::class)->record('outreach.video_removed', metadata: ['video' => $ulid]);
    }

    /**
     * @return \stdClass|null the video row (id, token, title, message, path, mime, user_id, contact_id, first_viewed_at, …)
     */
    public function byToken(string $token): ?\stdClass
    {
        $row = DB::connection(Connections::cell())->table('outreach_videos')->where('tenant_id', $this->tenants->id())->where('token', $token)->first();

        return $row instanceof \stdClass ? $row : null;
    }

    /** A view of the page: counted, and the first one goes on the contact's timeline for the agent to follow up. */
    public function viewed(\stdClass $video): void
    {
        $first = $video->first_viewed_at === null;
        DB::connection(Connections::cell())->table('outreach_videos')->where('id', (int) $video->id)
            ->update(['views' => DB::raw('views + 1'), 'last_viewed_at' => now(), 'first_viewed_at' => $video->first_viewed_at ?? now()]);
        $contact = $first && $video->contact_id !== null ? Contact::query()->whereKey((int) $video->contact_id)->first() : null;
        if ($contact instanceof Contact) {
            app(Timeline::class)->system($contact, 'Watched your video "'.$video->title.'".', (int) $video->user_id);
        }
    }

    private static function sniff(UploadedFile $file): ?string
    {
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 64);

        return match (true) {
            str_starts_with($head, "\x1A\x45\xDF\xA3") => 'video/webm',
            substr($head, 4, 4) === 'ftyp' && substr($head, 8, 2) === 'qt' => 'video/quicktime',
            substr($head, 4, 4) === 'ftyp' => 'video/mp4',
            default => null,
        };
    }
}
