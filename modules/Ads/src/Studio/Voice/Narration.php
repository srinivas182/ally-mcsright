<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Voice;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Jobs\RenderReel;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Designer;
use Modules\Ads\Studio\StudioPlans;
use Modules\Ads\Studio\StudioVideo;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Listings\Models\Listing;
use Throwable;

/**
 * Voice-overs for Reels and tours (specs/grow/13, SA5b).
 *
 * - Anyone with Studio can narrate in a professional stock voice.
 * - An agent can add their own voice, only for themselves, by signing the consent (their typed name) and recording
 *   it aloud with a short sample. Nobody else can make, see or use it. It narrates only videos they approve, never
 *   calls or texts, and is deleted when they withdraw, when they leave the brokerage, or after 12 months unused.
 * - Scripts are written by AI to fit the video, checked against Fair Housing wording, and editable before use.
 * - Each narration counts as one "Voice narration" (allowance, then credits); a failed one gives it back.
 */
final class Narration
{
    public const FEATURE = 'ads.studio_narration';

    public const CONSENT_VERSION = 1;

    /** Words read per second at a relaxed pace. */
    public const WORDS_PER_SECOND = 2.2;

    public const UNUSED_MONTHS = 12;

    public function __construct(private readonly VoiceVendor $vendor, private readonly StudioPlans $plans, private readonly TenantContext $tenants) {}

    /** What the agent agrees to, in their name. */
    public function consentText(User $user): string
    {
        return 'I, '.trim($user->first_name.' '.$user->last_name).', agree that '.$this->tenants->current()->name.' and RightAlly, with their voice service '
            .'ElevenLabs, may make an AI copy of my voice. It will be used only to narrate my own listing videos that I approve in Ally Studio, never for '
            .'calls, texts or anyone else\'s content, and it will never be sold or shared. I can delete it at any time in Ally Studio. It is deleted '
            .'automatically if I leave '.$this->tenants->current()->name.' or do not use it for '.self::UNUSED_MONTHS.' months.';
    }

    /** Read aloud after the consent statement, so the copy sounds natural (about a minute in all). */
    public const SAMPLE = 'Welcome inside. Sunlight pours through tall windows into an open living room with warm oak floors. The kitchen is made '
        .'for gathering, with a long island, quartz counters and a walk-in pantry. Upstairs, the main suite feels like a retreat, with a spa bath and a '
        .'quiet reading nook. Outside, a covered patio looks over a wide, green yard. Minutes from parks, coffee and the trail, this is a home you will '
        .'not want to miss. Call me today to book your private tour.';

    public function ownVoice(User $user): ?StudioVoice
    {
        return StudioVoice::query()->where('user_id', $user->id)->where('status', 'active')->latest('id')->first();
    }

    /**
     * Makes the agent's own voice from their signed and recorded consent.
     *
     * @param  list<UploadedFile>  $recordings
     */
    public function consent(User $user, string $typedName, bool $agree, array $recordings, ?string $ip): StudioVoice
    {
        if ($this->plans->current($user) === null) {
            throw ValidationException::withMessages(['voice' => 'Ally Studio is an add-on. Choose a Studio package first.']);
        }
        if ($this->ownVoice($user) !== null) {
            throw ValidationException::withMessages(['voice' => 'You already have your own voice. Delete it first to record it again.']);
        }
        $name = trim($user->first_name.' '.$user->last_name);
        $errors = array_filter([
            'agree' => $agree ? null : 'Tick the box to agree.',
            'name' => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $typedName))) === mb_strtolower($name) ? null : 'Type your full name exactly as shown: '.$name.'.',
            'recordings' => $recordings === [] || count($recordings) > 3 ? 'Record the statement and the sample (up to three clips).' : null,
        ]);
        $bytes = 0;
        foreach ($recordings as $r) {
            $bytes += (int) $r->getSize();
            if (! str_starts_with((string) $r->getMimeType(), 'audio/') && ! in_array($r->getMimeType(), ['video/webm', 'application/ogg'], true)) {
                $errors['recordings'] = 'The recording must be audio.';
            }
        }
        if (! isset($errors['recordings']) && ($bytes < 60_000 || $bytes > 25_000_000)) {
            $errors['recordings'] = $bytes < 60_000 ? 'The recording is too short: read the whole statement and the sample.' : 'The recording is too long.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $hash = hash_init('sha256');
        foreach ($recordings as $r) {
            hash_update_file($hash, (string) $r->getRealPath());
        }
        try {
            $ref = $this->vendor->cloneVoice('RightAlly '.$this->tenants->current()->ulid.' '.$user->id, array_map(fn (UploadedFile $r): string => (string) $r->getRealPath(), $recordings));
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['voice' => 'Your voice couldn\'t be made just now. Please try again in a few minutes.']);
        }
        $voice = StudioVoice::query()->create(['user_id' => $user->id, 'voice_ref' => $ref, 'status' => 'active', 'consent_name' => mb_substr(trim($typedName), 0, 120),
            'consent_text' => $this->consentText($user), 'consent_version' => self::CONSENT_VERSION, 'consent_ip' => $ip, 'recording_sha256' => hash_final($hash),
            'consent_at' => CarbonImmutable::now()]);
        app(AuditLogger::class)->record('studio.voice_consent', metadata: ['voice' => $voice->id, 'version' => self::CONSENT_VERSION], severity: 'notice');

        return $voice;
    }

    /** Deletes the agent's voice at the vendor and here (the consent record stays, without the voice). */
    public function withdraw(StudioVoice $voice, string $reason): void
    {
        if ($voice->status !== 'active') {
            return;
        }
        if (is_string($voice->voice_ref) && $voice->voice_ref !== '') {
            $this->vendor->delete($voice->voice_ref);
        }
        $voice->forceFill(['status' => 'deleted', 'voice_ref' => null, 'deleted_at' => CarbonImmutable::now(), 'deleted_reason' => mb_substr($reason, 0, 120)])->save();
        app(AuditLogger::class)->record('studio.voice_deleted', metadata: ['voice' => $voice->id, 'reason' => $reason], severity: 'notice');
    }

    /** Daily, per brokerage: voices of people who left, or unused for 12 months, are deleted. */
    public function sweep(): int
    {
        $n = 0;
        foreach (StudioVoice::query()->where('status', 'active')->get() as $v) {
            $user = User::query()->find($v->user_id);
            $last = $v->last_used_at ?? $v->consent_at;
            $why = $user === null || ! $user->isActive() ? 'Left the brokerage' : ($last->lt(CarbonImmutable::now()->subMonths(self::UNUSED_MONTHS)) ? 'Unused for 12 months' : null);
            if ($why !== null) {
                try {
                    $this->withdraw($v, $why);
                    $n++;
                } catch (Throwable $e) {
                    report($e); // tried again tomorrow
                }
            }
        }

        return $n;
    }

    /** @return list<array{key: string, label: string}> the voices this person can narrate with */
    public function voices(User $user): array
    {
        $out = $this->ownVoice($user) !== null ? [['key' => 'own', 'label' => 'My own voice']] : [];
        foreach ($this->vendor->stock() as $v) {
            $out[] = ['key' => 'stock:'.$v['id'], 'label' => $v['name'].($v['accent'] !== '' ? ' ('.$v['accent'].')' : '')];
        }

        return $out;
    }

    /** The most words a voice-over for this video can have. */
    public static function maxWords(StudioVideo $video): int
    {
        return max(12, (int) floor(max(1, $video->seconds - 1.5) * self::WORDS_PER_SECOND));
    }

    /** An AI first draft of the voice-over, fitted to the video and checked for Fair Housing wording. */
    public function draft(User $user, StudioVideo $video): string
    {
        abort_unless($video->user_id === $user->id && $video->status === 'ready', 404);
        $facts = $this->facts($video);
        $script = '';
        try {
            $r = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'ads.studio_narration', 'studio-narration:'.$video->ulid.':'.now()->timestamp,
                inputs: ['words' => (string) self::maxWords($video), 'kind' => $video->kind === 'tour' ? 'a one-minute YouTube tour' : 'a short Reel'],
                untrustedContent: [(string) json_encode($facts)], outputSchema: ['type' => 'object', 'properties' => ['script' => ['type' => 'string']]], userId: $user->id));
            $script = self::clean((string) ($r->json['script'] ?? ''));
        } catch (Throwable $e) {
            report($e);
        }
        if ($script === '' || FairHousing::screen($script, 'ads', 'removed', 'Ally Studio narration') !== [] || str_word_count($script) > self::maxWords($video) * 1.15) {
            $script = self::plain($facts); // never wording that fails the check, or more than fits
        }

        return $script;
    }

    /** Narrates the video: the voice-over is read and mixed over the music (re-made in the background). */
    public function narrate(User $user, StudioVideo $video, string $script, string $voice): void
    {
        abort_unless($video->user_id === $user->id, 404);
        if ($video->status !== 'ready') {
            throw ValidationException::withMessages(['narration' => 'Wait for the video to finish first.']);
        }
        $script = self::clean($script);
        $keys = array_column($this->voices($user), 'key');
        $errors = array_filter([
            'voice' => in_array($voice, $keys, true) ? null : 'Choose a voice.',
            'narration' => $script === '' ? 'Write the voice-over first.' : (str_word_count($script) > (int) ceil(self::maxWords($video) * 1.15)
                ? 'That\'s too long to read over this video: keep it to about '.self::maxWords($video).' words.' : null),
        ]);
        if ($script !== '' && ($flags = FairHousing::screen($script, 'ads', 'blocked', 'Ally Studio narration')) !== []) {
            $errors['narration'] = 'Fair Housing: please remove "'.implode('", "', $flags).'". Describe the home, not who should live there.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $this->plans->use($user, 'voice', $video->ulid.':'.substr(sha1($script.$voice), 0, 8));
        $video->forceFill(['narration' => $script, 'voice' => $voice, 'narration_error' => null, 'status' => 'rendering'])->save();
        RenderReel::dispatch($video->id);
        app(AuditLogger::class)->record('studio.narrated', metadata: ['video' => $video->ulid, 'voice' => $voice === 'own' ? 'own' : 'stock']);
    }

    /**
     * The voice-over audio for a video being made (an MP3 file), or null when it has none or it failed (the video
     * is then made without it, the narration is given back and the reason shown).
     */
    public function audioFor(StudioVideo $video, string $dir): ?string
    {
        if ($video->narration === null || $video->voice === null) {
            return null;
        }
        $user = User::query()->find($video->user_id);
        try {
            $own = $video->voice === 'own' && $user !== null ? $this->ownVoice($user) : null;
            $id = $video->voice === 'own' ? ($own->voice_ref ?? throw new \RuntimeException('Your own voice was deleted.')) : substr($video->voice, 6);
            $mp3 = $this->vendor->speak($id, $video->narration);
            $own?->forceFill(['last_used_at' => CarbonImmutable::now()])->save();
            file_put_contents($path = $dir.'/voice.mp3', $mp3);

            return $path;
        } catch (Throwable $e) {
            report($e);
            if ($user !== null) {
                $this->plans->giveBack($user, 'voice');
            }
            $video->forceFill(['narration_error' => 'The voice-over couldn\'t be added just now, so the video was made without it. Your narration is back in your allowance.',
                'narration' => null, 'voice' => null])->save();

            return null;
        }
    }

    /** @return array<string, mixed> */
    private function facts(StudioVideo $video): array
    {
        $design = $video->design_id !== null ? Design::query()->find($video->design_id) : null;
        $facts = $design !== null ? array_intersect_key($design->content, array_flip(['headline', 'sub', 'facts', 'price', 'body'])) : [];
        $home = $video->listing_ulid !== null ? Listing::query()->where('ulid', $video->listing_ulid)->first() : null;
        if ($home !== null) {
            $facts += array_filter(['address' => $home->address_display_allowed ? $home->address() : null, 'city' => $home->city, 'beds' => $home->beds_total,
                'price' => $home->list_price_cents !== null ? '$'.number_format(intdiv($home->list_price_cents, 100)) : null,
                'remarks' => is_string($home->public_remarks) ? mb_substr(strip_tags($home->public_remarks), 0, 1200) : null]);
        }
        if ($video->kind === 'tour') {
            $facts['rooms'] = array_values(array_filter(array_map(fn (array $c): string => $c['title'], $video->chapters ?? []),
                fn (string $t): bool => ! in_array($t, ['', 'Welcome', 'Book a tour'], true)));
        }
        $facts['moment'] = $design !== null ? (Designer::MOMENTS[$design->moment][0] ?? $design->moment) : 'Listing tour';

        return $facts;
    }

    /** @param  array<string, mixed>  $facts */
    private static function plain(array $facts): string
    {
        $where = trim(implode(' in ', array_filter([is_string($facts['address'] ?? null) ? $facts['address'] : null, is_string($facts['city'] ?? null) ? $facts['city'] : null])));

        $beds = is_numeric($facts['beds'] ?? null) && (int) $facts['beds'] > 0 ? (int) $facts['beds'].' bedrooms, ' : '';

        return trim(($where !== '' ? 'Welcome to '.$where.'. ' : 'Welcome home. ').'This home has '.$beds.'light-filled rooms and space to enjoy every day. '
            .'Book your private tour today.');
    }

    private static function clean(string $script): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($script)));
    }
}
