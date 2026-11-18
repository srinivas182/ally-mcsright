<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Appointments\Recordings;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Voice\CallNotesMail;
use Tests\Fixtures\FakeAiProvider;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/*
| VO2 (specs/listings/05): in-person appointments recorded only after everyone agrees, sent in pieces, written down,
| turned into notes and private coaching; the audio is deleted; the agent may share coaching with their broker.
*/

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Ann']);
    $this->seller = app(ManageContacts::class)->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@x.test'], $this->agent);
    $this->seller->forceFill(['origin' => Origin::Company])->save(); // a company lead, so the broker can open the appointment
    $this->actingAs($this->agent)->post('/appointments', ['kind' => 'listing', 'contact' => $this->seller->ulid]);
    $this->appt = (string) DB::connection(Connections::cell())->table('appt_consultations')->value('ulid');

    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => 'appointments.coaching', 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.appointments_coaching']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
});

function apTranscripts(string $provider = 'deepgram'): void
{
    DB::connection(Connections::control())->table('outside_services')->insert(['tenant_id' => 0, 'service' => 'transcripts', 'provider' => $provider, 'mode' => 'live',
        'test_keys' => Crypt::encryptString('{"api_key":"k"}'), 'live_keys' => Crypt::encryptString('{"api_key":"k"}'), 'test_contacts' => '', 'created_at' => now(), 'updated_at' => now()]);
}

function apPiece(int $n): UploadedFile
{
    return UploadedFile::fake()->createWithContent($n.'.webm', 'webm-audio-'.$n)->mimeType('audio/webm');
}

it('records only after everyone agrees, writes it down, makes notes and private coaching, and deletes the audio', function (): void {
    $this->actingAs($this->agent)->get('/appointments/'.$this->appt)->assertInertia(fn (Assert $p) => $p->where('recording.blocker', fn ($b) => str_contains((string) $b, 'not switched on')));
    apTranscripts();
    $this->actingAs($this->agent)->postJson('/appointments/'.$this->appt.'/recording', ['names' => 'Pat Lee'])->assertJsonValidationErrors('agreed');
    $rec = (string) $this->actingAs($this->agent)->postJson('/appointments/'.$this->appt.'/recording', ['names' => 'Pat Lee', 'agreed' => true])->assertOk()->json('recording');

    $row = DB::connection(Connections::cell())->table('appt_recordings')->sole();
    expect($row->consent_names)->toBe('Pat Lee')->and($row->consent_wording)->toBe(Recordings::WORDING)->and($row->consent_ip)->toBe('127.0.0.1');

    $this->actingAs($this->agent)->post('/appointments/recordings/'.$rec.'/piece', ['seq' => 1, 'audio' => apPiece(1)], ['Accept' => 'application/json'])->assertJsonValidationErrors('audio'); // out of order
    foreach ([0, 1, 1] as $n) { // the second "1" is a retried upload
        $this->actingAs($this->agent)->post('/appointments/recordings/'.$rec.'/piece', ['seq' => $n, 'audio' => apPiece($n)], ['Accept' => 'application/json'])->assertOk();
    }
    $other = User::factory()->create();
    $this->actingAs($other)->post('/appointments/recordings/'.$rec.'/piece', ['seq' => 2, 'audio' => apPiece(2)], ['Accept' => 'application/json'])->assertNotFound();

    Http::fake(['api.deepgram.com/*' => Http::response(['results' => ['utterances' => [
        ['speaker' => 0, 'transcript' => 'What matters most to you in this sale?'], ['speaker' => 1, 'transcript' => 'We need to move by August and hoped to get 525.'],
        ['speaker' => 0, 'transcript' => 'I will send the comparison tonight.']]]])]);
    $this->ai->respond((string) json_encode(['agent_speaker' => 'Speaker 1', 'summary' => 'Pat needs to move by August and hoped for 525.', 'client_wants' => ['Move by August'],
        'objections' => [['topic' => 'price', 'said' => 'That feels low.', 'handled' => 'partly']], 'follow_ups' => ['Send the comparison tonight'],
        'strengths' => ['Opened with a question'], 'try_next' => ['Ask what 525 is based on'], 'fair_housing' => []]));
    $this->actingAs($this->agent)->post('/appointments/recordings/'.$rec.'/stop', ['seconds' => 1800])->assertSessionHasNoErrors();

    Http::assertSent(fn (HttpRequest $r): bool => $r->body() === 'webm-audio-0webm-audio-1' && $r->hasHeader('Content-Type', 'audio/webm'));
    expect($this->ai->requests[0]->userMessage)->toContain('hoped to get 525');
    $row = DB::connection(Connections::cell())->table('appt_recordings')->sole();
    expect($row->status)->toBe('done')->and((int) $row->talk_share)->toBe(58) // 14 of 24 words (numbers are not counted)
        ->and(Storage::disk('local')->allFiles())->toBe([]); // the audio is gone
    Notification::assertSentTo($this->agent, CallNotesMail::class);

    $this->actingAs($this->agent)->get('/appointments/'.$this->appt)->assertInertia(fn (Assert $p) => $p->where('recording.list.0.notes.summary', 'Pat needs to move by August and hoped for 525.')
        ->where('recording.list.0.notes.try_next.0', 'Ask what 525 is based on')->where('recording.list.0.talk_share', 58)->where('recording.list.0.transcript', fn ($t) => str_contains((string) $t, 'Speaker 2: We need')));

    // The broker sees the notes but not the coaching or transcript until the agent shares it.
    $this->actingAs($this->owner)->get('/appointments/'.$this->appt)->assertInertia(fn (Assert $p) => $p->where('recording.list.0.notes.summary', 'Pat needs to move by August and hoped for 525.')
        ->missing('recording.list.0.notes.try_next')->where('recording.list.0.talk_share', null)->where('recording.list.0.transcript', null));
    $this->actingAs($this->agent)->post('/appointments/recordings/'.$rec.'/share', ['shared' => true]);
    $this->actingAs($this->owner)->get('/appointments/'.$this->appt)->assertInertia(fn (Assert $p) => $p->where('recording.list.0.notes.try_next.0', 'Ask what 525 is based on'));

    assertPageContract($this->actingAs($this->agent)->get('/appointments/coaching'), 'appointments/coaching');
    $this->actingAs($this->agent)->get('/appointments/coaching')->assertInertia(fn (Assert $p) => $p->where('trends.count', 1)->where('trends.talk_share', 58)
        ->where('trends.objections.0.objection', 'price'));

    $this->actingAs($this->agent)->post('/appointments/recordings/'.$rec.'/delete')->assertSessionHasNoErrors();
    expect(DB::connection(Connections::cell())->table('appt_recordings')->value('notes'))->toBeNull();
});

it('works with a provider that writes down in the background, and lets the agent try again after a failure', function (): void {
    apTranscripts('assemblyai');
    $rec = (string) $this->actingAs($this->agent)->postJson('/appointments/'.$this->appt.'/recording', ['names' => 'Pat Lee', 'agreed' => true])->json('recording');
    $this->actingAs($this->agent)->post('/appointments/recordings/'.$rec.'/piece', ['seq' => 0, 'audio' => apPiece(0)], ['Accept' => 'application/json']);
    $down = true;
    Http::fake(function (HttpRequest $r) use (&$down) {
        return match (true) {
            $down => Http::response([], 503),
            str_ends_with($r->url(), '/v2/upload') => Http::response(['upload_url' => 'https://cdn.example/x']),
            str_ends_with($r->url(), '/v2/transcript') => Http::response(['id' => 'tx_1']),
            default => Http::response(['status' => 'completed', 'utterances' => [['speaker' => 'A', 'text' => 'Hello there.']]]),
        };
    });
    $this->actingAs($this->agent)->post('/appointments/recordings/'.$rec.'/stop', ['seconds' => 60]);
    expect(DB::connection(Connections::cell())->table('appt_recordings')->value('status'))->toBe('failed')
        ->and(Storage::disk('local')->allFiles())->not->toBe([]); // kept for a retry

    $down = false;
    $this->ai->respond((string) json_encode(['agent_speaker' => 'Speaker A', 'summary' => 'A short hello.']));
    $this->actingAs($this->agent)->post('/appointments/recordings/'.$rec.'/retry')->assertSessionHasNoErrors();
    // The sync queue runs once: the job was started, and the next run fetches the text.
    app(Recordings::class)->transcribe($rec);
    $row = DB::connection(Connections::cell())->table('appt_recordings')->sole();
    expect($row->status)->toBe('done')->and(Crypt::decryptString($row->transcript))->toBe('Speaker A: Hello there.')->and((int) $row->talk_share)->toBe(100);
});

it('only lets the agent running the appointment record it', function (): void {
    apTranscripts();
    $this->actingAs($this->owner)->postJson('/appointments/'.$this->appt.'/recording', ['names' => 'Pat Lee', 'agreed' => true])->assertJsonValidationErrors('names');
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->postJson('/appointments/'.$this->appt.'/recording', ['names' => 'Pat Lee', 'agreed' => true])->assertNotFound();
});
