<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Transactions\Closing\AppointmentAnswerMail;
use Modules\Transactions\Closing\AppointmentMissedMail;
use Modules\Transactions\Closing\Appointments;
use Modules\Transactions\Closing\VendorMail;
use Modules\Transactions\Deals;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Portal\PortalCodeMail;
use Modules\Transactions\Portal\PortalMail;
use Modules\Transactions\Portal\PortalView;
use Modules\Transactions\Portal\Updates;

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    // Monday 10:00 in Austin.
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->actingAs($this->agent)->post('/deals', ['type' => 'buyer', 'street' => '412 Elm St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '450000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
    app(Deals::class)->moveTo($this->deal, 'under_contract', $this->agent, ['price_cents' => 45_000_000, 'gci_cents' => 1_350_000, 'contract_date' => '2026-05-28', 'closing_date' => '2026-06-30']);
    $this->deal->refresh();
});

/** Invites a client to the portal and signs this browser in; returns their access ulid. */
function closingPortal(object $test, string $role, string $email, string $locale = 'en'): string
{
    $test->actingAs($test->agent)->post("/deals/{$test->deal->ulid}/portal", ['role' => $role, 'name' => 'Client', 'email' => $email, 'locale' => $locale]);
    $token = null;
    Notification::assertSentOnDemand(PortalMail::class, function (PortalMail $m, $c, AnonymousNotifiable $to) use ($email, &$token): bool {
        if ($to->routes['mail'] !== $email || ! str_contains($m->url, '/my-deal/in/')) {
            return false;
        }
        $token = substr($m->url, strrpos($m->url, '/') + 1);

        return true;
    });
    auth()->logout();
    $test->post("/my-deal/in/{$token}/code", ['email' => $email]);
    $code = null;
    Notification::assertSentOnDemand(PortalCodeMail::class, function (PortalCodeMail $m, $c, AnonymousNotifiable $to) use ($email, &$code): bool {
        $code = $m->code;

        return $to->routes['mail'] === $email;
    });
    $ulid = (string) DB::table('tx_portal_access')->where('email', $email)->value('ulid');
    $test->post("/my-deal/in/{$token}/verify", ['code' => $code])->assertRedirect("/my-deal/{$ulid}");

    return $ulid;
}

it('keeps the brokerage\'s preferred vendors, editable by people who manage templates', function (): void {
    $this->actingAs($this->agent)->post('/deals/vendors', ['category' => 'inspector', 'name' => 'Ace Inspections'])->assertForbidden();
    $this->actingAs($this->owner)->post('/deals/vendors', ['category' => 'inspector', 'name' => 'Ace Inspections', 'email' => 'book@ace.test', 'phone' => '512-555-0101'])->assertRedirect();
    $this->actingAs($this->owner)->post('/deals/vendors', ['category' => 'internet', 'name' => 'FastNet', 'website' => 'http://fastnet.test'])->assertSessionHasErrors('website');
    $this->actingAs($this->owner)->post('/deals/vendors', ['category' => 'internet', 'name' => 'FastNet', 'website' => 'https://fastnet.test', 'note' => 'Free install for our clients'])->assertRedirect();
    $this->actingAs($this->agent)->get('/deals/vendors')->assertInertia(fn (Assert $page) => $page->component('deals/vendors')->has('vendors', 2)->where('canEdit', false));
});

it('books a vendor who confirms or suggests another time through their link, and the agent accepts it in one click', function (): void {
    $this->actingAs($this->owner)->post('/deals/vendors', ['category' => 'inspector', 'name' => 'Ace Inspections', 'email' => 'book@ace.test']);
    $vendor = (string) DB::table('tx_vendors')->value('ulid');
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/appointments", ['kind' => 'inspection', 'starts_at' => '2026-06-03 09:00', 'vendor' => $vendor])->assertRedirect();
    $token = null;
    Notification::assertSentOnDemand(VendorMail::class, function (VendorMail $m, $c, AnonymousNotifiable $to) use (&$token): bool {
        $token = substr((string) $m->url, strrpos((string) $m->url, '/') + 1);

        return $to->routes['mail'] === 'book@ace.test' && $m->starts->format('Y-m-d H:i') === '2026-06-03 09:00';
    });
    auth()->logout();
    $this->get("/appointment/{$token}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page->component('public/appointment')->where('appointment.title', 'Inspection'));

    $this->post("/appointment/{$token}", ['confirm' => false, 'proposed' => '2026-05-20 09:00'])->assertSessionHasErrors('proposed');
    $this->post("/appointment/{$token}", ['confirm' => false, 'proposed' => '2026-06-04 13:00', 'note' => 'Booked in the morning'])->assertRedirect("/appointment/{$token}?done=suggested");
    Notification::assertSentTo($this->agent, AppointmentAnswerMail::class, fn (AppointmentAnswerMail $m): bool => ! $m->confirmed && $m->note === 'Booked in the morning');
    $a = Appointments::forDeal($this->deal)[0];
    expect($a['status'])->toBe('reschedule')->and($a['proposed_at'])->not->toBeNull();

    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/appointments/{$a['ulid']}/accept")->assertRedirect();
    $a = Appointments::forDeal($this->deal)[0];
    expect($a['status'])->toBe('confirmed')->and(CarbonImmutable::parse($a['starts_at'])->setTimezone('America/Chicago')->format('Y-m-d H:i'))->toBe('2026-06-04 13:00');
    Notification::assertSentOnDemandTimes(VendorMail::class, 2); // the request, then the new time
    auth()->logout();
    $this->post("/appointment/{$token}", ['confirm' => true])->assertRedirect();
    expect(Appointments::forDeal($this->deal)[0]['status'])->toBe('confirmed');
});

it('reminds vendors the day before and flags a missed appointment with the deadline that depends on it', function (): void {
    DB::table('tx_dates')->insert(['tenant_id' => $this->deal->tenant_id, 'deal_id' => $this->deal->id, 'label' => 'Option period ends', 'due_on' => '2026-06-05', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/appointments", ['kind' => 'inspection', 'starts_at' => '2026-06-02 09:00', 'vendor_name' => 'Ace', 'vendor_email' => 'book@ace.test']);
    $ulid = (string) DB::table('tx_appointments')->value('ulid');
    DB::table('tx_appointments')->update(['status' => 'confirmed']);
    Notification::fake();
    expect(app(Appointments::class)->run())->toMatchArray(['vendor_reminders' => 1, 'missed' => 0])
        ->and(app(Appointments::class)->run()['vendor_reminders'])->toBe(0);
    Notification::assertSentOnDemand(VendorMail::class, fn (VendorMail $m): bool => $m->reminder);

    $this->travelTo(CarbonImmutable::parse('2026-06-03 15:00', 'UTC'));
    expect(app(Appointments::class)->run()['missed'])->toBe(1);
    Notification::assertSentTo($this->agent, AppointmentMissedMail::class, fn (AppointmentMissedMail $m): bool => $m->depends === ['Option period ends (Fri Jun 5)']);
    expect(Appointments::forDeal($this->deal)[0]['status'])->toBe('missed');
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/appointments/{$ulid}/done")->assertRedirect();
    expect(Appointments::forDeal($this->deal)[0]['status'])->toBe('done');
});

it('gives clients a moving checklist dated from their closing, with the brokerage\'s vendors, in their language', function (): void {
    $this->actingAs($this->owner)->post('/deals/vendors', ['category' => 'internet', 'name' => 'FastNet', 'phone' => '512-555-0199', 'note' => 'Free install']);
    $this->actingAs($this->owner)->post('/deals/vendors', ['category' => 'mover', 'name' => 'Hidden Movers', 'show_clients' => false]);
    $ulid = closingPortal($this, 'buyer', 'ana@example.test', 'es');
    $this->get("/my-deal/{$ulid}")->assertInertia(fn (Assert $page) => $page->has('moving', 7)
        ->where('moving.0.key', 'movers')->where('moving.0.due_on', '2026-05-31')->where('moving.0.overdue', true)->where('moving.0.vendors', [])
        ->where('moving.1.key', 'internet')->where('moving.1.label', 'Contrate internet y TV para la casa nueva (la instalación puede tardar 1 a 2 semanas)')
        ->where('moving.1.due', 'martes, 16 de junio')->where('moving.1.vendors.0.name', 'FastNet')
        ->where('moving.3.link', 'https://moversguide.usps.com/'));
    $this->post("/my-deal/{$ulid}/moving/internet")->assertRedirect("/my-deal/{$ulid}");
    $this->post("/my-deal/{$ulid}/moving/castle")->assertNotFound();
    $this->get("/my-deal/{$ulid}")->assertInertia(fn (Assert $page) => $page->where('moving.1.done', true));
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('portal.people.0.moving', '1 of 7'));
});

it('sends the closing-day plan three days before, with the title company\'s number from the deal', function (): void {
    DB::table('tx_parties')->insert(['tenant_id' => $this->deal->tenant_id, 'deal_id' => $this->deal->id, 'role' => 'title', 'name' => 'Jo Escrow', 'company' => 'Lone Star Title',
        'phone' => '512-555-0100', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/closing", ['closing_time' => '14:00', 'closing_place' => '100 Congress Ave, Austin'])->assertRedirect();
    $ulid = closingPortal($this, 'buyer', 'ana@example.test');
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/appointments", ['kind' => 'walkthrough', 'starts_at' => '2026-06-29 17:00']);
    auth()->logout();
    Notification::fake();
    expect(app(Updates::class)->run()['closing'])->toBe(0); // a month out

    $this->travelTo(CarbonImmutable::parse('2026-06-27 15:00', 'UTC'));
    expect(app(Updates::class)->run()['closing'])->toBe(1)->and(app(Updates::class)->run()['closing'])->toBe(0);
    Notification::assertSentOnDemand(PortalMail::class, fn (PortalMail $m): bool => $m->subject === 'Closing day plan: 412 Elm St'
        && $m->lines[0] === 'When: Tuesday, June 30, 2:00 PM' && $m->lines[1] === 'Where: 100 Congress Ave, Austin'
        && in_array('Final walk-through: Monday, June 29, 5:00 PM', $m->lines, true)
        && str_contains((string) $m->lines[count($m->lines) - 1], 'call Lone Star Title on 512-555-0100') && $m->replyTo === $this->agent->email);
    $page = PortalView::page($this->deal->refresh(), DB::table('tx_portal_access')->where('ulid', $ulid)->first()); // the sign-in from weeks ago has expired
    expect($page['closingDay'][0])->toBe('When: Tuesday, June 30, 2:00 PM')->and($page['appointments'][0]['title'])->toBe('Final walk-through');
});

it('stays in touch after closing: keys the next day, a month on, and each home anniversary with a call-back task', function (): void {
    closingPortal($this, 'buyer', 'ana@example.test');
    closingPortal($this, 'seller', 'sam@example.test');
    $this->deal->update(['status' => 'closed', 'closed_on' => '2026-06-01', 'stage_shown' => 'closed']);
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-02 15:00', 'UTC'));
    expect(app(Updates::class)->run()['after'])->toBe(1); // the buyer only
    Notification::assertSentOnDemand(PortalMail::class, fn (PortalMail $m, $c, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'ana@example.test' && $m->subject === 'Welcome home: 412 Elm St');

    $this->travelTo(CarbonImmutable::parse('2026-07-01 15:00', 'UTC'));
    expect(app(Updates::class)->run()['after'])->toBe(2); // a month on: both

    $this->travelTo(CarbonImmutable::parse('2027-06-01 15:00', 'UTC'));
    expect(app(Updates::class)->run()['after'])->toBe(1)->and(app(Updates::class)->run()['after'])->toBe(0);
    Notification::assertSentOnDemand(PortalMail::class, fn (PortalMail $m): bool => $m->subject === 'Happy home anniversary!');
    expect(DB::table('crm_tasks')->where('title', 'like', 'Call Client: 1 year(s) in 412 Elm St%')->count())->toBe(1);
});
