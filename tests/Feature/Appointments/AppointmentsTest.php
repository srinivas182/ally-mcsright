<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Appointments\Mail\GuideMail;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Task;
use Modules\Transactions\Models\Deal;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* AP1 (specs/listings/05): guided listing appointments, buyer consultations and the buyer-agreement guard. */

beforeEach(function (): void {
    Storage::fake('local');
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'phone' => '+15125550123']);
    $this->seller = app(ManageContacts::class)->create(['first_name' => 'Sam', 'last_name' => 'Seller', 'email' => 'sam@x.test'], $this->agent);
    app(ManageContacts::class)->update($this->seller, ['address_line1' => '12 Oak St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704'], $this->agent);
    $this->buyer = app(ManageContacts::class)->create(['first_name' => 'Bea', 'last_name' => 'Buyer', 'email' => 'bea@x.test'], $this->agent);
});

it('walks a listing appointment from the home to a listing deal and emails the seller a summary', function (): void {
    assertPageContract($this->actingAs($this->agent)->get('/appointments?contact='.$this->seller->ulid), 'appointments/index');
    $this->actingAs($this->agent)->post('/appointments', ['kind' => 'listing', 'contact' => $this->seller->ulid])->assertRedirect();
    $ulid = (string) DB::table('appt_consultations')->value('ulid');
    assertPageContract($this->actingAs($this->agent)->get("/appointments/{$ulid}"), 'appointments/show');
    $this->actingAs($this->agent)->get("/appointments/{$ulid}")->assertInertia(fn (Assert $p) => $p->where('c.answers.home.street', '12 Oak St')->where('c.net', null));

    $this->actingAs($this->agent)->post("/appointments/{$ulid}/save", ['step' => 'price', 'values' => ['list_price' => 450000]])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->post("/appointments/{$ulid}/save", ['step' => 'net', 'values' => ['payoff' => 200000]]);
    $this->actingAs($this->agent)->post("/appointments/{$ulid}/save", ['step' => 'terms', 'values' => ['listing_pct' => 3, 'months' => 6, 'buyer_pct' => 2]]);
    $this->actingAs($this->agent)->get("/appointments/{$ulid}")->assertInertia(fn (Assert $p) => $p->where('c.net.price_cents', 45_000_000)
        ->where('c.net.net_cents', 45_000_000 - 20_000_000 - 1_350_000 - 900_000 - 675_000)); // payoff, 3%, 2%, TX closing 1.5%

    $this->actingAs(User::factory()->create())->get("/appointments/{$ulid}")->assertNotFound(); // another agent
    $this->actingAs($this->agent)->post("/appointments/{$ulid}/finish", ['send_guide' => true])->assertRedirect();
    $deal = Deal::query()->sole();
    expect($deal->type)->toBe('listing')->and($deal->street)->toBe('12 Oak St')->and($deal->price_cents)->toBe(45_000_000)
        ->and((string) $deal->getAttribute('listing_expires_on'))->toStartWith('2026-12-01')->and($deal->contact_id)->toBe($this->seller->id);
    Mail::assertSent(GuideMail::class, fn (GuideMail $m): bool => $m->hasTo('sam@x.test') && $m->kind === 'listing');
    expect(DB::table('appt_consultations')->value('status'))->toBe('done');
});

it('records a specific buyer agreement and stops showings until it is signed', function (): void {
    Lead::query()->create(['contact_id' => $this->buyer->id, 'source' => 'website', 'channel' => 'direct', 'status' => 'responded', 'intent' => 'buy']);
    $this->actingAs($this->agent)->post('/tasks', ['contact' => $this->buyer->ulid, 'title' => 'Tour 44 Pecan Dr', 'type' => 'showing', 'due' => 'tomorrow'])
        ->assertSessionHasErrors('agreement');
    expect(Task::query()->count())->toBe(0);

    $this->actingAs($this->agent)->post('/appointments', ['kind' => 'buyer', 'contact' => $this->buyer->ulid]);
    $ulid = (string) DB::table('appt_consultations')->value('ulid');
    $this->actingAs($this->agent)->post("/appointments/{$ulid}/save", ['step' => 'agreement', 'values' => ['comp_kind' => 'percent', 'comp_value' => 0, 'months' => 3]])
        ->assertSessionHasErrors('comp_value'); // never open-ended
    $this->actingAs($this->agent)->post("/appointments/{$ulid}/save", ['step' => 'agreement', 'values' => ['comp_kind' => 'flat', 'comp_value' => 9000, 'months' => 4, 'exclusive' => true]])
        ->assertSessionHasNoErrors();
    $pdf = UploadedFile::fake()->createWithContent('signed.pdf', "%PDF-1.4\n%%EOF");
    $this->actingAs($this->agent)->post("/appointments/{$ulid}/signed", ['signed_on' => '2026-06-01', 'file' => $pdf])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->get("/appointments/{$ulid}")->assertInertia(fn (Assert $p) => $p->where('c.agreement.status', 'signed')->where('c.agreement.ends_on', '2026-10-01'));
    $this->actingAs($this->agent)->get("/appointments/{$ulid}/agreement")->assertOk();

    $this->actingAs($this->agent)->post('/tasks', ['contact' => $this->buyer->ulid, 'title' => 'Tour 44 Pecan Dr', 'type' => 'showing', 'due' => 'tomorrow'])->assertSessionHasNoErrors();
    expect(Task::query()->where('type', 'showing')->count())->toBe(1);

    // Expired agreements stop showings again; a seller (no buyer lead) is never stopped.
    $this->travelTo(CarbonImmutable::parse('2026-10-15 15:00', 'UTC'));
    $this->actingAs($this->agent)->post('/tasks', ['contact' => $this->buyer->ulid, 'title' => 'Tour 9 Elm', 'type' => 'showing', 'due' => 'tomorrow'])->assertSessionHasErrors('agreement');
    $this->actingAs($this->agent)->post('/tasks', ['contact' => $this->seller->ulid, 'title' => 'Walk-through', 'type' => 'showing', 'due' => 'tomorrow'])->assertSessionHasNoErrors();

    $this->actingAs($this->agent)->post("/appointments/{$ulid}/finish", ['send_guide' => true]);
    Mail::assertSent(GuideMail::class, fn (GuideMail $m): bool => $m->hasTo('bea@x.test') && $m->agreementSigned === true);
});
