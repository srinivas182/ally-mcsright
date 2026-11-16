<?php

declare(strict_types=1);

namespace Modules\Ads\Studio;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * RightAlly's licensed music for Reels and Shorts (specs/grow/13, SA3). HQ adds each track with the licence it holds
 * (a royalty-free library subscription that covers social video for RightAlly's customers, or tracks it owns), so
 * agents never pick music they may not use. Tracks are kept on the private disk and only mixed into videos.
 */
final class MusicLibrary
{
    public const MOODS = ['luxury' => 'Luxury', 'calm' => 'Calm', 'upbeat' => 'Upbeat', 'cinematic' => 'Cinematic'];

    /** @return list<array{ulid: string, title: string, artist: string, mood: string, licence: string, licence_url: string|null, seconds: int, active: bool}> */
    public function all(bool $activeOnly = false): array
    {
        $out = [];
        foreach (DB::connection(Connections::control())->table('studio_music')->when($activeOnly, fn ($q) => $q->where('active', true))->orderBy('mood')->orderBy('title')->get() as $t) {
            $out[] = ['ulid' => (string) $t->ulid, 'title' => (string) $t->title, 'artist' => (string) $t->artist, 'mood' => (string) $t->mood,
                'licence' => (string) $t->licence, 'licence_url' => is_string($t->licence_url) ? $t->licence_url : null, 'seconds' => (int) $t->seconds, 'active' => (bool) $t->active];
        }

        return $out;
    }

    /** @param array<string, mixed> $in title, artist, mood, licence, licence_url */
    public function add(UploadedFile $file, array $in, User $by): string
    {
        $mood = (string) ($in['mood'] ?? '');
        $licence = trim(strip_tags((string) ($in['licence'] ?? '')));
        if (! isset(self::MOODS[$mood]) || $licence === '' || trim((string) ($in['title'] ?? '')) === '') {
            throw ValidationException::withMessages(['licence' => 'Add the title, the mood and the licence RightAlly holds for this track.']);
        }
        $url = trim((string) ($in['licence_url'] ?? ''));
        $ulid = strtolower((string) Str::ulid());
        $path = 'studio-music/'.$ulid.'.'.(in_array($file->extension(), ['mp3', 'm4a', 'aac', 'wav'], true) ? $file->extension() : 'mp3');
        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));
        DB::connection(Connections::control())->table('studio_music')->insert(['ulid' => $ulid, 'title' => mb_substr(trim(strip_tags((string) $in['title'])), 0, 120),
            'artist' => mb_substr(trim(strip_tags((string) ($in['artist'] ?? ''))), 0, 120), 'mood' => $mood, 'licence' => mb_substr($licence, 0, 300),
            'licence_url' => str_starts_with($url, 'https://') ? mb_substr($url, 0, 300) : null, 'path' => $path, 'seconds' => $this->seconds(Storage::disk('local')->path($path)),
            'active' => true, 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('studio.music_added', metadata: ['track' => $ulid, 'mood' => $mood], severity: 'notice');

        return $ulid;
    }

    public function toggle(string $ulid, bool $active, User $by): void
    {
        DB::connection(Connections::control())->table('studio_music')->where('ulid', $ulid)->update(['active' => $active, 'updated_at' => now()]);
        app(AuditLogger::class)->record('studio.music_'.($active ? 'on' : 'off'), metadata: ['track' => $ulid], severity: 'notice');
    }

    /**
     * A track for a video: the one asked for, else the next active track in the mood (rotating), else any active one.
     *
     * @return array{ulid: string, file: string}|null
     */
    public function pick(?string $ulid, string $mood, int $turn): ?array
    {
        $q = fn () => DB::connection(Connections::control())->table('studio_music')->where('active', true);
        $t = $ulid !== null ? $q()->where('ulid', $ulid)->first() : null;
        if ($t === null) {
            $pool = $q()->where('mood', $mood)->orderBy('id')->get();
            $pool = $pool->isEmpty() ? $q()->orderBy('id')->get() : $pool;
            $t = $pool->isEmpty() ? null : $pool[$turn % $pool->count()];
        }

        return $t === null ? null : ['ulid' => (string) $t->ulid, 'file' => Storage::disk('local')->path((string) $t->path)];
    }

    private function seconds(string $file): int
    {
        try {
            $p = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $file]);
            $p->setTimeout(20);
            $p->run();

            return (int) round((float) trim($p->getOutput()));
        } catch (Throwable) {
            return 0;
        }
    }
}
