<?php

declare(strict_types=1);

namespace Modules\Crm\Voice;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\VoiceCaller;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Inbox\SendGuard;
use Modules\Crm\Leads\AiResponder;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\Contact;
use stdClass;
use Throwable;

/**
 * Recorded calls with automatic notes (VO1 / D08, specs/grow/14). The agent presses one button: RightAlly rings the
 * agent's own phone, then the client. Before they are connected the client hears that the call is recorded for notes
 * (in English and Spanish), so everyone on the call is told, as all-party-consent states require; the agent hears
 * the same. When the call ends the recording is written down by the transcripts provider, the AI writes the
 * agent's notes (summary, next step, follow-up task), and the audio is deleted from the phone provider. Only the
 * transcript and notes are kept, encrypted, on the contact.
 */
final class RecordedCalls
{
    public function __construct(private readonly TenantContext $tenants, private readonly MessagingSettings $messaging, private readonly Timeline $timeline) {}

    /** Why a recorded call can't be made now (null when it can). */
    public function blocker(Contact $contact, User $agent): ?string
    {
        return match (true) {
            app(OutsideServices::class)->active('transcripts', $this->tenants->id()) === null => 'Call notes are not switched on yet (RightAlly HQ turns them on in Outside services).',
            ! $this->messaging->voiceFor($this->tenants->id()) => 'Calls are not switched on for this brokerage yet (RightAlly HQ turns them on).',
            $agent->phone === null || trim((string) $agent->phone) === '' => 'Add your mobile number to your profile first: RightAlly rings you, then them.',
            $contact->primary_phone === null => 'Add their phone number first.',
            $contact->do_not_call => 'They are on Do Not Call.',
            default => SendGuard::quietHours((string) $contact->primary_phone) !== null ? 'It is outside 8am to 9pm where they are.' : null,
        };
    }

    /** Starts the call: rings the agent; when they answer, the client is called. Returns the call's id. */
    public function start(Contact $contact, User $agent): string
    {
        $why = $this->blocker($contact, $agent);
        if ($why !== null) {
            throw ValidationException::withMessages(['call' => $why]);
        }
        $db = DB::connection(Connections::cell());
        $busy = $db->table('crm_recorded_calls')->where('tenant_id', $this->tenants->id())->where('user_id', $agent->id)->where('status', 'calling')
            ->where('created_at', '>=', CarbonImmutable::now()->subMinutes(2))->exists();
        if ($busy) {
            throw ValidationException::withMessages(['call' => 'Your last call is still ringing. Wait a moment.']);
        }
        $ulid = (string) Str::ulid();
        $db->table('crm_recorded_calls')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'contact_id' => $contact->id, 'user_id' => $agent->id,
            'status' => 'calling', 'created_at' => now(), 'updated_at' => now()]);
        $sid = app(VoiceCaller::class)->bridge((string) $agent->phone, $this->url($ulid, 'agent'));
        $db->table('crm_recorded_calls')->where('ulid', $ulid)->update(['call_sid' => $sid !== '' ? mb_substr($sid, 0, 64) : null]);
        $this->timeline->system($contact, $agent->first_name.' started a recorded call (with notes).', $agent->id);
        app(AuditLogger::class)->record('crm.recorded_call.started', metadata: ['contact' => $contact->ulid], severity: 'info');

        return $ulid;
    }

    /**
     * What the phone provider should do next. $step: agent (the agent answered), client (the client answered),
     * done (the recording is ready).
     *
     * @param  array<string, mixed>  $input
     */
    public function twiml(string $ulid, string $step, array $input): string
    {
        $call = $this->find($ulid);
        if ($call === null) {
            return self::respond('<Hangup/>');
        }
        $contact = Contact::query()->find((int) $call->contact_id);
        $agent = User::query()->find((int) $call->user_id);
        if ($contact === null || $agent === null) {
            return self::respond('<Hangup/>');
        }
        $brand = (string) app(AiResponder::class)->settings()['brand_name'];
        $e = fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES);

        return match ($step) {
            'agent' => self::respond('<Say voice="Polly.Joanna">'.$e('Calling '.$contact->first_name.'. They will hear that the call is recorded for notes.').'</Say>'
                .'<Dial record="record-from-answer-dual" recordingStatusCallback="'.$e($this->url($ulid, 'done')).'" recordingStatusCallbackEvent="completed" timeout="30">'
                .'<Number url="'.$e($this->url($ulid, 'client')).'">'.$e((string) $contact->primary_phone).'</Number></Dial>'),
            'client' => self::respond('<Say voice="Polly.Joanna">'.$e('Hello, this is '.$agent->first_name.' from '.$brand.'. This call is recorded so I can keep accurate notes. If you prefer, hang up and I will call you back without recording.').'</Say>'
                .'<Say voice="Polly.Lupe" language="es-US">'.$e('Esta llamada se graba para tomar notas.').'</Say>'),
            'done' => $this->recorded($call, $input),
            default => self::respond(''),
        };
    }

    /**
     * Writes down the recording and makes the notes. Returns false while the transcripts provider is still working.
     */
    public function transcribe(string $ulid): bool
    {
        $call = $this->find($ulid);
        if ($call === null || ! in_array($call->status, ['recorded', 'writing'], true) || $call->recording_sid === null) {
            return true;
        }
        $service = app(OutsideServices::class)->active('transcripts', $this->tenants->id());
        if ($service === null) {
            $this->update($call, ['status' => 'failed', 'reason' => 'Call notes were switched off before this call was written down.']);

            return true;
        }
        try {
            $transcriber = app(Transcriber::class);
            if ($call->transcript_job !== null) {
                $text = $transcriber->fetch($service['provider'], $service['keys'], (string) $call->transcript_job);
                if ($text === null) {
                    return false;
                }
            } else {
                $started = $transcriber->start($service['provider'], $service['keys'], app(VoiceCaller::class)->recording((string) $call->recording_sid));
                if ($started['job'] !== null) {
                    $this->update($call, ['status' => 'writing', 'transcript_job' => mb_substr($started['job'], 0, 64)]);

                    return false;
                }
                $text = (string) $started['text'];
            }
        } catch (Throwable $e) {
            report($e);
            $this->update($call, ['status' => 'failed', 'reason' => 'The call could not be written down. Try again, or delete the recording.']);

            return true;
        }
        $contact = Contact::query()->find((int) $call->contact_id);
        $agent = User::query()->find((int) $call->user_id);
        $notes = ['summary' => 'The call was too short to summarise.', 'next_step' => '', 'follow_up' => 'none', 'wants_agent' => false, 'stop_calls' => false];
        if ($contact !== null && trim($text) !== '') {
            try {
                $notes = app(CallNotes::class)->write($contact, $text, 'recorded', $agent?->id);
            } catch (Throwable $e) {
                report($e);
                $notes['summary'] = 'The call was written down; the AI notes could not be made. Open the transcript below.';
            }
        }
        $this->update($call, ['status' => 'written', 'transcript' => Crypt::encryptString($text), 'summary' => Crypt::encryptString($notes['summary'])]);
        try {
            app(VoiceCaller::class)->deleteRecording((string) $call->recording_sid); // only the words are kept
        } catch (Throwable $e) {
            report($e);
        }
        if ($contact !== null) {
            app(CallNotes::class)->deliver($contact, $notes, $agent, 'Recorded call notes', $call->seconds !== null ? (int) $call->seconds : null, true);
        }

        return true;
    }

    /** Tries writing down a call again after the transcripts provider failed. */
    public function retry(string $ulid, User $agent): void
    {
        $call = $this->mine($ulid, $agent);
        if ($call->status !== 'failed' || $call->recording_sid === null) {
            throw ValidationException::withMessages(['call' => 'This call can not be tried again.']);
        }
        $this->update($call, ['status' => 'recorded', 'transcript_job' => null, 'reason' => null]);
        TranscribeCall::dispatch($ulid);
    }

    /** Deletes a recording that could not be written down (nothing is kept). */
    public function discard(string $ulid, User $agent): void
    {
        $call = $this->mine($ulid, $agent);
        if ($call->status !== 'failed') {
            throw ValidationException::withMessages(['call' => 'Only a call that could not be written down can be deleted here.']);
        }
        if ($call->recording_sid !== null) {
            app(VoiceCaller::class)->deleteRecording((string) $call->recording_sid);
        }
        $this->update($call, ['status' => 'deleted', 'recording_sid' => null, 'reason' => 'The recording was deleted without notes.']);
    }

    /**
     * Calls with notes on one contact, newest first (AI conversations and recorded calls), for the contact page.
     *
     * @return list<array{ulid: string, kind: string, when: string, status: string, seconds: int|null, summary: string|null, transcript: string|null}>
     */
    public function forContact(Contact $contact): array
    {
        $db = DB::connection(Connections::cell());
        $open = fn (?string $v): ?string => $v === null ? null : rescue(fn (): string => Crypt::decryptString($v), null, false);
        $rows = [];
        foreach ($db->table('crm_recorded_calls')->where('tenant_id', $this->tenants->id())->where('contact_id', $contact->id)->latest('id')->limit(20)->get() as $r) {
            $rows[] = ['ulid' => (string) $r->ulid, 'kind' => 'recorded', 'when' => (string) $r->created_at, 'status' => (string) $r->status,
                'seconds' => $r->seconds !== null ? (int) $r->seconds : null, 'summary' => $open($r->summary), 'transcript' => $open($r->transcript)];
        }
        foreach ($db->table('crm_ai_calls')->where('tenant_id', $this->tenants->id())->where('contact_id', $contact->id)->whereNotNull('provider')->latest('id')->limit(20)->get() as $r) {
            $rows[] = ['ulid' => (string) $r->ulid, 'kind' => 'ai_call', 'when' => (string) $r->created_at, 'status' => (string) $r->status,
                'seconds' => null, 'summary' => $open($r->summary), 'transcript' => $open($r->transcript)];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['when'], $a['when']));

        return array_slice($rows, 0, 20);
    }

    public function find(string $ulid): ?stdClass
    {
        return DB::connection(Connections::cell())->table('crm_recorded_calls')->where('ulid', $ulid)->where('tenant_id', $this->tenants->id())->first();
    }

    private function mine(string $ulid, User $agent): stdClass
    {
        $call = $this->find($ulid);
        if ($call === null || (int) $call->user_id !== $agent->id) {
            throw ValidationException::withMessages(['call' => 'That call was not found.']);
        }

        return $call;
    }

    /** @param  array<string, mixed>  $input */
    private function recorded(stdClass $call, array $input): string
    {
        $sid = (string) ($input['RecordingSid'] ?? '');
        $seconds = (int) ($input['RecordingDuration'] ?? 0);
        if ($call->status !== 'calling') {
            return self::respond('');
        }
        if ($sid === '' || ($input['RecordingStatus'] ?? 'completed') !== 'completed' || $seconds < 5) {
            $this->update($call, ['status' => 'no_recording', 'reason' => 'The call was not answered or was too short to write down.', 'recording_sid' => $sid !== '' ? mb_substr($sid, 0, 64) : null]);
            if ($sid !== '') {
                rescue(fn () => app(VoiceCaller::class)->deleteRecording($sid), null, true);
            }

            return self::respond('');
        }
        $this->update($call, ['status' => 'recorded', 'recording_sid' => mb_substr($sid, 0, 64), 'seconds' => $seconds]);
        TranscribeCall::dispatch((string) $call->ulid);

        return self::respond('');
    }

    /** @param  array<string, mixed>  $values */
    private function update(stdClass $call, array $values): void
    {
        DB::connection(Connections::cell())->table('crm_recorded_calls')->where('id', $call->id)->update($values + ['updated_at' => now()]);
    }

    private function url(string $ulid, string $step): string
    {
        return app(SpeedToLead::class)->link(URL::temporarySignedRoute('crm.recorded-call.step', CarbonImmutable::now()->addHours(3), ['call' => $ulid, 'step' => $step], false));
    }

    private static function respond(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Response>'.$inner.'</Response>';
    }
}
