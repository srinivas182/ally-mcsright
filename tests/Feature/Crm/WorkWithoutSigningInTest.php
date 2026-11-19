<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Brief\DailyBriefMail;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Brief\MorningList;
use Modules\Crm\Models\Activity;

/*
| SX6: work without signing in — today's list in the morning email, a morning text only for agents who turn it on,
| a no-sign-in list page that logs calls with a button (never the link itself), and concierge setup.
*/

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 13:30', 'UTC')); // Monday 8:30am in Austin
    $this->tenant = $this->onTenantHost('acme');
    $control = DB::connection(Connections::control());
    $control->table('hq_messaging')->insert(['credentials' => Crypt::encryptString(json_encode(['sid' => 'AC'.str_repeat('1', 32), 'token' => 'x'])),
        'sms_from' => '+15125550000', 'voice_from' => '+15125550000', 'sms_enabled' => true, 'voice_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    $control->table('hq_messaging_tenants')->insert(['tenant_id' => $this->tenant->id, 'sms' => true, 'voice' => false, 'sms_number' => '+15125550100', 'created_at' => now(), 'updated_at' => now()]);
    app()->instance(SmsSender::class, $this->sms = new FakeSmsSender);
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'phone' => '+15125550111']);
    $this->bob = User::factory()->create(['first_name' => 'Bob', 'phone' => '+15125550112']);
    $this->maria = app(ManageContacts::class)->create(['first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '5125550120', 'lifecycle' => 'lead'], $this->agent);
});

it('puts today\'s people and a no-sign-in link in the morning email', function (): void {
    app(DailyBriefs::class)->sendDue();

    Notification::assertSentTo($this->agent, DailyBriefMail::class, function (DailyBriefMail $m): bool {
        $html = implode("\n", $m->toMail($this->agent)->introLines);

        return $m->people[0]['name'] === 'Maria Lopez' && str_contains((string) $m->listLink, '/morning/'.$this->agent->ulid.'?expires=') && str_contains($html, 'Call today');
    });
});

it('shows the list without signing in, logs a call only with the button, and refuses a changed or expired link', function (): void {
    $link = app(MorningList::class)->link($this->agent);
    $path = (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);

    $this->get($path)->assertOk()->assertSee('Good morning, Pat')->assertSee('Maria Lopez')->assertSee('tel:+15125550120', false);
    expect(Activity::query()->where('type', 'call')->count())->toBe(0); // opening the link changes nothing

    $this->from($path)->post($path, ['contact' => $this->maria->ulid, 'outcome' => 'connected'])->assertRedirect();
    expect(Activity::query()->where('type', 'call')->where('outcome', 'connected')->where('contact_id', $this->maria->id)->count())->toBe(1);

    // Another agent's link cannot log on Pat's people; a tampered or expired link is refused.
    $bobs = app(MorningList::class)->link($this->bob);
    $bobPath = (string) parse_url($bobs, PHP_URL_PATH).'?'.parse_url($bobs, PHP_URL_QUERY);
    $this->post($bobPath, ['contact' => $this->maria->ulid, 'outcome' => 'no_answer'])->assertNotFound();
    $this->get(str_replace($this->agent->ulid, $this->bob->ulid, $path))->assertForbidden();
    $this->travel(1)->days();
    $this->get($path)->assertForbidden();
});

it('texts the morning list only to agents who turned it on, after 8am their time, once a day', function (): void {
    app(MorningList::class)->textDue();
    expect($this->sms->sent)->toBe([]);

    $this->actingAs($this->agent)->post('/brief/morning-text', ['on' => true])->assertSessionHasNoErrors();
    $this->artisan('crm:morning-texts')->assertSuccessful();
    $this->artisan('crm:morning-texts')->assertSuccessful();
    expect($this->sms->sent)->toHaveCount(1)->and($this->sms->sent[0]['to'] ?? $this->sms->sent[0][0] ?? null)->toBe('+15125550111')
        ->and(json_encode($this->sms->sent[0]))->toContain('1 person to call today')->toContain('Reply STOP');

    $this->actingAs($this->agent)->post('/brief/morning-text', ['on' => false]);
    $this->travel(1)->days();
    $this->artisan('crm:morning-texts');
    expect($this->sms->sent)->toHaveCount(1);
});

it('takes a spreadsheet for concierge setup, lets only HQ download it (audited), and deletes it after 30 days', function (): void {
    $this->actingAs($this->agent)->post('/help/concierge', ['file' => UploadedFile::fake()->createWithContent('past clients.csv', "name,phone\nJane,5125550199\n"), 'what' => 'contacts'])
        ->assertSessionHasNoErrors();
    $t = DB::connection(Connections::control())->table('support_tickets')->sole();
    expect($t->topic)->toBe('concierge')->and($t->file_name)->toBe('past clients.csv')
        ->and((string) Storage::disk('local')->get((string) $t->file_path))->not->toContain('Jane'); // stored encrypted

    $this->actingAs($this->agent)->get('/hq/support/'.$t->ulid.'/file')->assertNotFound();
    $this->actingAs($this->agent)->post('/help/concierge', ['file' => UploadedFile::fake()->create('virus.exe', 10), 'what' => 'contacts'])->assertSessionHasErrors('file');

    $this->travel(31)->days();
    $this->artisan('team:concierge-cleanup')->assertSuccessful();
    expect(Storage::disk('local')->exists((string) $t->file_path))->toBeFalse();
});
