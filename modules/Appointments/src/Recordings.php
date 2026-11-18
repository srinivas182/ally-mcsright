<?php

declare(strict_types=1);

namespace Modules\Appointments;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Contact;
use Modules\Crm\Voice\CallNotesMail;
use Modules\Crm\Voice\Transcriber;
use stdClass;
use Throwable;

/**
 * Recorded appointments with notes and coaching (VO2 / B27, specs/listings/05).
 *
 * 1. Consent first: the agent reads a short script aloud and types the names of everyone present who agreed. The
 *    wording, names, time and IP are kept as proof. Nothing records without it.
 * 2. The phone or laptop records in the browser and sends the audio in 30-second pieces, so a dropped connection
 *    loses at most a few seconds.
 * 3. When the agent stops, the audio is written down by the transcripts provider (Outside services), the AI writes
 *    the appointment notes (what the client wants, concerns raised and how they were answered, follow-ups) and
 *    private coaching for the agent (strengths, what to try next, how much the agent talked, Fair Housing
 *    reminders). The audio pieces are then deleted; the transcript and notes are kept encrypted.
 * 4. Coaching is the agent's own. They can share it with their broker; until then only the agent sees it.
 */
final class Recordings
{
    public const FEATURE = 'appointments.coaching';

    /** The script the agent reads aloud before recording. */
    public const WORDING = 'Before we start: I\'d like to record our conversation so I can keep accurate notes and get coaching on how I explain things. Only I see the recording\'s notes, and the audio is deleted once it is written down. Is that all right with everyone? If not, I won\'t record.';

    /** At most 30-second pieces for three hours. */
    private const MAX_PARTS = 360;

    private const MIMES = ['audio/webm', 'video/webm', 'audio/ogg', 'audio/mp4', 'video/mp4', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'application/octet-stream'];

    public function __construct(private readonly TenantContext $tenants, private readonly Consultations $consultations) {}

    /** Why recording can't start (null when it can). */
    public function blocker(): ?string
    {
        return app(OutsideServices::class)->active('transcripts', $this->tenants->id()) === null
            ? 'Recording is not switched on yet (RightAlly HQ turns on call and appointment notes in Outside services).' : null;
    }

    /** Records everyone's consent and opens a recording. */
    public function start(string $consultation, string $names, ?string $ip, User $user): string
    {
        $row = $this->consultations->open($consultation, $user);
        if ((int) $row->user_id !== $user->id) {
            throw ValidationException::withMessages(['names' => 'Only the agent running this appointment can record it.']);
        }
        if (($why = $this->blocker()) !== null) {
            throw ValidationException::withMessages(['names' => $why]);
        }
        $names = trim(preg_replace('/\s+/', ' ', $names) ?? '');
        if (mb_strlen($names) < 2) {
            throw ValidationException::withMessages(['names' => 'Type the names of everyone here who agreed to the recording.']);
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('appt_recordings')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'consultation_id' => $row->id,
            'user_id' => $user->id, 'status' => 'recording', 'consent_names' => mb_substr($names, 0, 300), 'consent_wording' => self::WORDING,
            'consent_ip' => $ip, 'consent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $contact = Contact::query()->find((int) $row->contact_id);
        if ($contact !== null) {
            app(Timeline::class)->system($contact, 'Appointment recording started with everyone\'s consent ('.mb_substr($names, 0, 120).').', $user->id);
        }
        app(AuditLogger::class)->record('appointments.recording_consent', metadata: ['consultation' => $row->ulid, 'recording' => $ulid], severity: 'notice');

        return $ulid;
    }

    /** One 30-second piece of audio, in order. */
    public function piece(string $ulid, int $seq, UploadedFile $audio, User $user): void
    {
        $rec = $this->mine($ulid, $user);
        if ($rec->status !== 'recording') {
            throw ValidationException::withMessages(['audio' => 'This recording has already stopped.']);
        }
        if ($seq !== (int) $rec->parts) {
            if ($seq < (int) $rec->parts) {
                return; // already have it (a retried upload)
            }
            throw ValidationException::withMessages(['audio' => 'A piece of the recording is missing. Keep the page open; it sends again.']);
        }
        if ($seq >= self::MAX_PARTS) {
            throw ValidationException::withMessages(['audio' => 'Recordings stop at three hours.']);
        }
        $mime = (string) $audio->getMimeType();
        if (! in_array($mime, self::MIMES, true) || $audio->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['audio' => 'That is not audio from the recorder.']);
        }
        Storage::disk('local')->put($this->dir($rec).'/'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT).'.part', (string) file_get_contents($audio->getRealPath()));
        $values = ['parts' => $seq + 1, 'updated_at' => now()];
        if ($seq === 0) {
            $type = (string) strtok($audio->getClientMimeType() !== '' ? $audio->getClientMimeType() : $mime, ';');
            $values['mime'] = mb_substr(str_starts_with($type, 'video/') ? 'audio/'.substr($type, 6) : $type, 0, 40);
        }
        DB::connection(Connections::cell())->table('appt_recordings')->where('id', $rec->id)->update($values);
    }

    /** The agent stopped recording: write it down in the background. */
    public function stop(string $ulid, int $seconds, User $user): void
    {
        $rec = $this->mine($ulid, $user);
        if ($rec->status !== 'recording') {
            return;
        }
        if ((int) $rec->parts === 0) {
            $this->discard($ulid, $user);

            return;
        }
        $this->update($rec, ['status' => 'uploaded', 'seconds' => max(1, min(3 * 3600, $seconds))]);
        TranscribeAppointment::dispatch($ulid);
    }

    /** Deletes the audio (and any notes) of a recording. */
    public function discard(string $ulid, User $user): void
    {
        $rec = $this->mine($ulid, $user);
        Storage::disk('local')->deleteDirectory($this->dir($rec));
        $this->update($rec, ['status' => 'deleted', 'transcript' => null, 'notes' => null, 'reason' => 'Deleted by the agent.']);
        app(AuditLogger::class)->record('appointments.recording_deleted', metadata: ['recording' => $rec->ulid], severity: 'info');
    }

    public function share(string $ulid, bool $shared, User $user): void
    {
        $this->update($this->mine($ulid, $user), ['shared' => $shared]);
    }

    /** Writes down and makes the notes. Returns false while the transcripts provider is still working. */
    public function transcribe(string $ulid): bool
    {
        $rec = DB::connection(Connections::cell())->table('appt_recordings')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first();
        if ($rec === null || ! in_array($rec->status, ['uploaded', 'writing'], true)) {
            return true;
        }
        $service = app(OutsideServices::class)->active('transcripts', $this->tenants->id());
        if ($service === null) {
            $this->update($rec, ['status' => 'failed', 'reason' => 'Recording notes were switched off before this was written down.']);

            return true;
        }
        try {
            $transcriber = app(Transcriber::class);
            if ($rec->transcript_job !== null) {
                $text = $transcriber->fetch($service['provider'], $service['keys'], (string) $rec->transcript_job);
                if ($text === null) {
                    return false;
                }
            } else {
                $started = $transcriber->start($service['provider'], $service['keys'], $this->audio($rec), (string) ($rec->mime ?? 'audio/webm'));
                if ($started['job'] !== null) {
                    $this->update($rec, ['status' => 'writing', 'transcript_job' => mb_substr($started['job'], 0, 64)]);

                    return false;
                }
                $text = (string) $started['text'];
            }
        } catch (Throwable $e) {
            report($e);
            $this->update($rec, ['status' => 'failed', 'reason' => 'The recording could not be written down. Try again, or delete it.']);

            return true;
        }
        $consultation = DB::connection(Connections::cell())->table('appt_consultations')->where('id', $rec->consultation_id)->first();
        $contact = $consultation !== null ? Contact::query()->find((int) $consultation->contact_id) : null;
        $agent = User::query()->find((int) $rec->user_id);
        $notes = self::emptyNotes();
        if (trim($text) !== '' && $consultation !== null) {
            try {
                $result = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'appointments.coaching', 'appt-coaching:'.$rec->ulid,
                    inputs: ['kind' => $consultation->kind === 'listing' ? 'a listing appointment with a seller' : 'a buyer consultation',
                        'agent_name' => $agent !== null ? $agent->first_name : 'the agent', 'client_name' => $contact !== null ? trim($contact->first_name.' '.$contact->last_name) : 'the client'],
                    untrustedContent: [mb_substr($text, 0, 120_000)],
                    outputSchema: ['type' => 'object', 'required' => ['summary'], 'properties' => ['summary' => ['type' => 'string']]], userId: $agent?->id));
                $notes = self::clean((array) $result->json);
            } catch (Throwable $e) {
                report($e);
                $notes['summary'] = 'The appointment was written down; the AI notes could not be made. Open the transcript.';
            }
        }
        $this->update($rec, ['status' => 'done', 'transcript' => Crypt::encryptString($text), 'notes' => Crypt::encryptString((string) json_encode($notes)),
            'talk_share' => self::talkShare($text, $notes['agent_speaker']), 'transcript_job' => null]);
        Storage::disk('local')->deleteDirectory($this->dir($rec)); // only the words are kept
        if ($contact !== null && $notes['summary'] !== '') {
            app(Timeline::class)->system($contact, 'Appointment notes: '.$notes['summary'], $agent?->id);
        }
        if ($agent !== null && $consultation !== null) {
            $agent->notify(new CallNotesMail($contact !== null ? trim($contact->first_name.' '.$contact->last_name) : 'your appointment', 'Appointment notes and coaching',
                $notes['summary'], $notes['follow_ups'][0] ?? '', url('/appointments/'.$consultation->ulid)));
        }

        return true;
    }

    /** Tries writing down again after the transcripts provider failed. */
    public function retry(string $ulid, User $user): void
    {
        $rec = $this->mine($ulid, $user);
        if ($rec->status !== 'failed') {
            throw ValidationException::withMessages(['recording' => 'This recording can not be tried again.']);
        }
        $this->update($rec, ['status' => 'uploaded', 'transcript_job' => null, 'reason' => null]);
        TranscribeAppointment::dispatch($ulid);
    }

    /**
     * The recordings of one appointment that this user may see: the agent sees everything; a manager sees the notes
     * always and the coaching only when the agent shared it.
     *
     * @return list<array<string, mixed>>
     */
    public function forConsultation(stdClass $consultation, User $user): array
    {
        $own = (int) $consultation->user_id === $user->id;
        $rows = DB::connection(Connections::cell())->table('appt_recordings')->where('tenant_id', $this->tenants->id())->where('consultation_id', $consultation->id)
            ->latest('id')->get();
        $out = [];
        foreach ($rows as $r) {
            $notes = $r->notes !== null ? (array) json_decode((string) rescue(fn (): string => Crypt::decryptString((string) $r->notes), '{}', false), true) : null;
            $coaching = $own || (bool) $r->shared;
            if ($notes !== null && ! $coaching) {
                unset($notes['strengths'], $notes['try_next'], $notes['fair_housing']);
            }
            $out[] = ['ulid' => (string) $r->ulid, 'status' => (string) $r->status, 'when' => (string) $r->created_at, 'seconds' => $r->seconds !== null ? (int) $r->seconds : null,
                'consent' => ['names' => (string) $r->consent_names, 'at' => (string) $r->consent_at], 'notes' => $notes,
                'talk_share' => $coaching && $r->talk_share !== null ? (int) $r->talk_share : null, 'shared' => (bool) $r->shared, 'own' => $own, 'reason' => $r->reason,
                'transcript' => $own && $r->transcript !== null ? rescue(fn (): string => Crypt::decryptString((string) $r->transcript), null, false) : null];
        }

        return $out;
    }

    /**
     * The agent's coaching over their last ten recorded appointments: how much they talked, concerns that keep coming
     * up, and what to work on.
     *
     * @return array{count: int, talk_share: int|null, objections: list<array{objection: string, count: int}>, try_next: list<string>}
     */
    public function trends(User $user): array
    {
        $rows = DB::connection(Connections::cell())->table('appt_recordings')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->where('status', 'done')
            ->latest('id')->limit(10)->get();
        $shares = $rows->pluck('talk_share')->filter(fn ($v): bool => $v !== null)->map(fn ($v): int => (int) $v);
        $objections = [];
        $tries = [];
        foreach ($rows as $r) {
            $n = (array) json_decode((string) rescue(fn (): string => Crypt::decryptString((string) $r->notes), '{}', false), true);
            foreach ((array) ($n['objections'] ?? []) as $o) {
                $key = mb_strtolower(trim((string) ($o['topic'] ?? '')));
                if ($key !== '') {
                    $objections[$key] = ($objections[$key] ?? 0) + 1;
                }
            }
            foreach ((array) ($n['try_next'] ?? []) as $t) {
                $tries[] = (string) $t;
            }
        }
        arsort($objections);

        $top = array_slice($objections, 0, 5, true);
        $average = $shares->isEmpty() ? null : (int) round(array_sum($shares->all()) / $shares->count());

        return ['count' => $rows->count(), 'talk_share' => $average,
            'objections' => array_map(fn (string $k, int $c): array => ['objection' => $k, 'count' => $c], array_keys($top), $top),
            'try_next' => array_slice($tries, 0, 3)];
    }

    /**
     * Share of words the agent spoke, from "Speaker N: …" lines.
     */
    public static function talkShare(string $transcript, string $agentSpeaker): ?int
    {
        if ($agentSpeaker === '') {
            return null;
        }
        $words = [];
        foreach (preg_split('/\R/', $transcript) ?: [] as $line) {
            if (preg_match('/^(Speaker [^:]{1,10}):\s*(.*)$/', trim($line), $m) === 1) {
                $words[$m[1]] = ($words[$m[1]] ?? 0) + str_word_count($m[2]);
            }
        }
        $total = array_sum($words);

        return $total > 0 && isset($words[$agentSpeaker]) ? (int) round(100 * $words[$agentSpeaker] / $total) : null;
    }

    /**
     * @param  array<mixed>  $j
     * @return array{agent_speaker: string, summary: string, client_wants: list<string>, objections: list<array{topic: string, said: string, handled: string}>, follow_ups: list<string>, strengths: list<string>, try_next: list<string>, fair_housing: list<string>}
     */
    public static function clean(array $j): array
    {
        $list = fn (string $k, int $max): array => array_values(array_filter(array_map(fn ($v): string => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, 300), array_slice((array) ($j[$k] ?? []), 0, $max)), fn (string $v): bool => $v !== ''));
        $objections = [];
        foreach (array_slice((array) ($j['objections'] ?? []), 0, 8) as $o) {
            if (is_array($o) && trim((string) ($o['topic'] ?? '')) !== '') {
                $handled = (string) ($o['handled'] ?? 'partly');
                $objections[] = ['topic' => mb_substr(trim((string) $o['topic']), 0, 60), 'said' => mb_substr(trim((string) ($o['said'] ?? '')), 0, 300),
                    'handled' => in_array($handled, ['well', 'partly', 'not'], true) ? $handled : 'partly'];
            }
        }
        $speaker = trim((string) ($j['agent_speaker'] ?? ''));

        return ['agent_speaker' => preg_match('/^Speaker [^:]{1,10}$/', $speaker) === 1 ? $speaker : '', 'summary' => mb_substr(trim((string) ($j['summary'] ?? '')), 0, 1500),
            'client_wants' => $list('client_wants', 8), 'objections' => $objections, 'follow_ups' => $list('follow_ups', 6),
            'strengths' => $list('strengths', 4), 'try_next' => $list('try_next', 4), 'fair_housing' => $list('fair_housing', 4)];
    }

    /** @return array{agent_speaker: string, summary: string, client_wants: list<string>, objections: list<array{topic: string, said: string, handled: string}>, follow_ups: list<string>, strengths: list<string>, try_next: list<string>, fair_housing: list<string>} */
    private static function emptyNotes(): array
    {
        return self::clean(['summary' => 'The recording was too short to write notes.']);
    }

    private function mine(string $ulid, User $user): stdClass
    {
        $rec = DB::connection(Connections::cell())->table('appt_recordings')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first();
        if ($rec === null || (int) $rec->user_id !== $user->id) {
            abort(404);
        }

        return $rec;
    }

    private function audio(stdClass $rec): string
    {
        $disk = Storage::disk('local');
        $files = $disk->files($this->dir($rec));
        sort($files);

        return implode('', array_map(fn (string $f): string => (string) $disk->get($f), $files));
    }

    private function dir(stdClass $rec): string
    {
        return 'appointments/'.$this->tenants->id().'/recordings/'.$rec->ulid;
    }

    /** @param  array<string, mixed>  $values */
    private function update(stdClass $rec, array $values): void
    {
        DB::connection(Connections::cell())->table('appt_recordings')->where('id', $rec->id)->update($values + ['updated_at' => now()]);
    }
}
