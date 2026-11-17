<?php

declare(strict_types=1);

namespace Modules\Engage;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Studio\Voice\VoiceVendor;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Tenancy\TenantContext;
use Modules\Engage\Models\BrokerVoice;
use Modules\Engage\Models\Recognition;
use Modules\Learn\Models\Launch;
use Modules\Transactions\Models\Deal;
use Throwable;

/**
 * Recognition messages from the broker (EN1, E21, specs/engage/01). RightAlly lists the milestones worth a word:
 * work anniversaries, a first closing and the 5th, 10th and 25th of the year, and a finished New Agent Launch. The
 * broker sends a short note with:
 *  - their own recorded voice (recorded in the browser), or
 *  - an AI copy of their voice, only after they signed and recorded a consent that covers recognition messages to
 *    their own agents and nothing else (separate from Ally Studio's listing narration). Every AI-voiced message says
 *    so to the agent. The broker can delete the voice at any time; it is deleted at the voice service too.
 * The broker always presses Send: nothing is sent on its own.
 */
final class Recognitions
{
    public const OCCASIONS = ['anniversary' => 'Work anniversary', 'first_closing' => 'First closing', 'closings_5' => '5 closings this year',
        'closings_10' => '10 closings this year', 'closings_25' => '25 closings this year', 'launch' => 'Finished the New Agent Launch', 'other' => 'Just because'];

    public function __construct(private readonly VoiceVendor $vendor, private readonly TenantContext $tenants) {}

    public static function leads(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff], true);
    }

    /** What the broker agrees to, in their name. */
    public function consentText(User $user): string
    {
        return 'I, '.trim($user->first_name.' '.$user->last_name).', agree that '.$this->tenants->current()->name.' and RightAlly, with their voice service '
            .'ElevenLabs, may make an AI copy of my voice. It will be used only to speak recognition messages that I write and send to agents of '
            .$this->tenants->current()->name.', each marked as my AI voice, never for clients, calls, texts, marketing or anyone else\'s words, and it '
            .'will never be sold or shared. I can delete it at any time, and it is deleted if I leave '.$this->tenants->current()->name.'.';
    }

    public function voice(User $broker): ?BrokerVoice
    {
        return BrokerVoice::query()->where('user_id', $broker->id)->where('status', 'active')->latest('id')->first();
    }

    public function consent(User $broker, string $typedName, bool $agree, UploadedFile $recording, ?string $ip): BrokerVoice
    {
        abort_unless(self::leads($broker), 403);
        if ($this->voice($broker) !== null) {
            throw ValidationException::withMessages(['voice' => 'You already have an AI voice. Delete it first to record it again.']);
        }
        $name = trim($broker->first_name.' '.$broker->last_name);
        $errors = array_filter([
            'agree' => $agree ? null : 'Tick the box to agree.',
            'name' => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $typedName))) === mb_strtolower($name) ? null : 'Type your full name exactly as shown: '.$name.'.',
            'recording' => self::isAudio($recording) && $recording->getSize() >= 60_000 && $recording->getSize() <= 25_000_000 ? null
                : 'Record yourself reading the statement aloud (about a minute).',
        ]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        try {
            $ref = $this->vendor->cloneVoice('RightAlly recognition '.$this->tenants->current()->ulid.' '.$broker->id, [(string) $recording->getRealPath()]);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['voice' => 'Your voice couldn\'t be made just now. Please try again in a few minutes.']);
        }
        $voice = BrokerVoice::query()->create(['user_id' => $broker->id, 'voice_ref' => $ref, 'status' => 'active', 'consent_name' => mb_substr(trim($typedName), 0, 120),
            'consent_text' => $this->consentText($broker), 'consent_ip' => $ip, 'recording_sha256' => hash_file('sha256', (string) $recording->getRealPath()),
            'consent_at' => CarbonImmutable::now()]);
        app(AuditLogger::class)->record('engage.voice_consent', metadata: ['voice' => $voice->id], severity: 'notice');

        return $voice;
    }

    public function withdraw(User $broker): void
    {
        $voice = $this->voice($broker);
        if ($voice === null) {
            return;
        }
        try {
            if ($voice->voice_ref !== null) {
                $this->vendor->delete($voice->voice_ref);
            }
        } catch (Throwable $e) {
            report($e);
        }
        $voice->forceFill(['voice_ref' => null, 'status' => 'deleted', 'deleted_at' => CarbonImmutable::now()])->save();
        app(AuditLogger::class)->record('engage.voice_deleted', metadata: ['voice' => $voice->id], severity: 'notice');
    }

    /**
     * Milestones in the last or next 14 days that have not been recognised yet.
     *
     * @return list<array{user_id: int, name: string, occasion: string, label: string, when: string, suggestion: string}>
     */
    public function milestones(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $sent = Recognition::query()->where('created_at', '>=', $today->subDays(60))->get()->map(fn (Recognition $r): string => $r->to_user_id.':'.$r->occasion)->all();
        $out = [];
        foreach (User::query()->where('kind', UserKind::Agent->value)->where('status', UserStatus::Active->value)->get() as $a) {
            $add = function (string $occasion, CarbonImmutable $when, string $suggestion) use (&$out, $a, $sent): void {
                if (! in_array($a->id.':'.$occasion, $sent, true)) {
                    $out[] = ['user_id' => $a->id, 'name' => (string) $a->name, 'occasion' => $occasion, 'label' => self::OCCASIONS[$occasion], 'when' => $when->toDateString(),
                        'suggestion' => $suggestion];
                }
            };
            $joined = CarbonImmutable::parse((string) $a->created_at);
            $anniversary = $joined->setYear($today->year);
            $years = $today->year - $joined->year;
            if ($years >= 1 && abs($today->diffInDays($anniversary, false)) <= 14) {
                $add('anniversary', $anniversary, "Happy {$years}-year anniversary, {$a->first_name}! Thank you for everything you bring to our office.");
            }
            $closed = Deal::query()->where('agent_user_id', $a->id)->where('status', 'closed')->whereYear('closed_on', $today->year)->orderBy('closed_on')->pluck('closed_on')->values();
            $ever = Deal::query()->where('agent_user_id', $a->id)->where('status', 'closed')->count();
            foreach ([1 => 'first_closing', 5 => 'closings_5', 10 => 'closings_10', 25 => 'closings_25'] as $n => $occasion) {
                $on = $closed->get($n - 1);
                if ($on === null || ($n === 1 && $ever > 1)) {
                    continue;
                }
                $date = CarbonImmutable::parse((string) $on);
                if ($date->gte($today->subDays(14))) {
                    $add($occasion, $date, $n === 1 ? "Congratulations on your first closing, {$a->first_name}! A big moment, and the first of many."
                        : "{$n} closings this year, {$a->first_name}! That is real work and it shows. Thank you.");
                }
            }
            $launch = Launch::query()->where('user_id', $a->id)->whereNotNull('finished_at')->first();
            if ($launch !== null && $launch->finished_at !== null && $launch->finished_at->gte($today->subDays(14))) {
                $add('launch', $launch->finished_at, "You finished your first 30 days, {$a->first_name}. Well done, and welcome properly to the team.");
            }
        }
        usort($out, fn (array $x, array $y): int => $y['when'] <=> $x['when']);

        return $out;
    }

    /** Sends a recognition: words, plus the broker's recording or (with consent) their AI voice. */
    public function send(User $broker, User $agent, string $occasion, string $text, ?UploadedFile $recording, bool $aiVoice): Recognition
    {
        abort_unless(self::leads($broker), 403);
        $text = trim(strip_tags($text));
        if (! isset(self::OCCASIONS[$occasion]) || $agent->status !== UserStatus::Active || $agent->id === $broker->id) {
            throw ValidationException::withMessages(['occasion' => 'Choose the agent and the occasion.']);
        }
        if ($text === '' || mb_strlen($text) > 600) {
            throw ValidationException::withMessages(['text' => 'Write a short message (up to 600 characters).']);
        }
        $r = Recognition::query()->create(['from_user_id' => $broker->id, 'to_user_id' => $agent->id, 'occasion' => $occasion, 'text' => $text, 'audio_kind' => 'none']);
        $dir = 'engage/'.$this->tenants->id().'/recognitions/';
        if ($recording !== null) {
            if (! self::isAudio($recording) || $recording->getSize() > 5_000_000) {
                $r->delete();
                throw ValidationException::withMessages(['recording' => 'The recording must be audio, under five minutes.']);
            }
            Storage::disk('local')->put($dir.$r->ulid.'.'.($recording->guessExtension() ?: 'webm'), (string) file_get_contents((string) $recording->getRealPath()));
            $r->update(['audio_path' => $dir.$r->ulid.'.'.($recording->guessExtension() ?: 'webm'), 'audio_kind' => 'recorded']);
        } elseif ($aiVoice) {
            $voice = $this->voice($broker);
            if ($voice === null || $voice->voice_ref === null) {
                $r->delete();
                throw ValidationException::withMessages(['voice' => 'Set up your AI voice first, or record the message yourself.']);
            }
            try {
                Storage::disk('local')->put($dir.$r->ulid.'.mp3', $this->vendor->speak($voice->voice_ref, $text));
                $r->update(['audio_path' => $dir.$r->ulid.'.mp3', 'audio_kind' => 'ai_voice']);
            } catch (Throwable $e) {
                report($e); // the written message still goes
            }
        }
        $r->update(['sent_at' => CarbonImmutable::now()]);
        $agent->notify(new RecognitionMail((string) $broker->name, self::OCCASIONS[$occasion], $text, '/engage/recognitions/'.$r->ulid, $r->audio_kind));
        app(AuditLogger::class)->record('engage.recognition.sent', metadata: ['recognition' => $r->ulid, 'to' => $agent->id, 'occasion' => $occasion, 'audio' => $r->audio_kind]);

        return $r;
    }

    private static function isAudio(UploadedFile $f): bool
    {
        $mime = (string) $f->getMimeType();

        return str_starts_with($mime, 'audio/') || in_array($mime, ['video/webm', 'application/ogg'], true);
    }
}
