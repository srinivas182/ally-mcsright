<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Core\Support\Connections;
use Modules\Transactions\DealIq\EmailIntakeDone;
use Modules\Transactions\DealIq\EmailIntakeRefused;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Timeline\StatePacks;
use Modules\Transactions\Timeline\Timeline;

/*
| DQ4 (specs/ai/02): Deal-IQ intake by email (each agent's own address; only mail the agent forwards is read), the
| same inbound email through SendGrid or Mailgun, and nine more state rule packs with their standard windows.
*/

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    config(['crm.inbound_email_secret' => 'inbound-secret-123456']);
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@acme.test']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
});

function dqAddress(object $t): string
{
    $address = '';
    $t->actingAs($t->agent)->get('/deals')->assertInertia(function (Assert $p) use (&$address): void {
        $p->where('dealIqEmail', function ($a) use (&$address): bool {
            $address = (string) $a;

            return str_starts_with($address, 'dealiq-') && str_ends_with($address, '@inbound.rightally.app');
        });
    });
    auth()->guard('web')->logout();

    return $address;
}

function dqPdf(): string
{
    return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
}

it('starts a new deal from a contract the agent forwards to their Deal-IQ address', function (): void {
    $address = dqAddress($this);
    $payload = ['OriginalRecipient' => $address, 'From' => 'Pat Lee <PAT@acme.test>', 'Subject' => 'Fwd: Executed contract 12 Oak St', 'TextBody' => 'See attached.',
        'MessageID' => 'm-1', 'Attachments' => [['Name' => 'contract.pdf', 'ContentType' => 'application/pdf', 'Content' => base64_encode(dqPdf())]]];
    $this->withBasicAuth('postmark', 'inbound-secret-123456')->postJson('/webhooks/inbound/lead-email', $payload)->assertOk()->assertJson(['status' => 'created']);

    $deal = Deal::query()->sole();
    expect($deal->agent_user_id)->toBe($this->agent->id)->and($deal->setup_status)->toBe('reading')
        ->and(Document::query()->where('deal_id', $deal->id)->value('filename'))->toBe('contract.pdf');
    Notification::assertSentTo($this->agent, EmailIntakeDone::class, fn (EmailIntakeDone $n): bool => $n->deal === $deal->ulid);

    // No attachment: nothing is created, and the agent is told.
    $this->withBasicAuth('postmark', 'inbound-secret-123456')->postJson('/webhooks/inbound/lead-email', [...$payload, 'Attachments' => []])->assertJson(['status' => 'no_attachment']);
    expect(Deal::query()->count())->toBe(1);
});

it('ignores mail to the address from anyone but the agent or their own assistant', function (): void {
    $address = dqAddress($this);
    $payload = ['OriginalRecipient' => $address, 'From' => 'stranger@evil.test', 'Subject' => 'Contract', 'TextBody' => '', 'MessageID' => 'm-2',
        'Attachments' => [['Name' => 'contract.pdf', 'ContentType' => 'application/pdf', 'Content' => base64_encode(dqPdf())]]];
    $this->withBasicAuth('postmark', 'inbound-secret-123456')->postJson('/webhooks/inbound/lead-email', $payload)->assertJson(['status' => 'refused']);
    expect(Deal::query()->count())->toBe(0);
    Notification::assertSentTo($this->agent, EmailIntakeRefused::class, fn (EmailIntakeRefused $n): bool => $n->from === 'stranger@evil.test');

    User::factory()->assistantOf($this->agent)->create(['email' => 'sam@acme.test']);
    $this->withBasicAuth('postmark', 'inbound-secret-123456')->postJson('/webhooks/inbound/lead-email', [...$payload, 'From' => 'sam@acme.test'])->assertJson(['status' => 'created']);
    expect(Deal::query()->sole()->agent_user_id)->toBe($this->agent->id);
});

it('takes the same email from SendGrid or Mailgun when HQ chose them, and checks each', function (): void {
    $address = dqAddress($this);
    $form = fn (array $extra) => $extra + ['subject' => 'Fwd: contract', 'text' => 'Attached'];

    // SendGrid: only with the secret in the address, and only when SendGrid is the chosen provider.
    $sendgrid = $form(['to' => $address, 'from' => 'Pat Lee <pat@acme.test>', 'envelope' => json_encode(['to' => [$address]]), 'attachments' => 1,
        'attachment-info' => json_encode(['attachment1' => ['filename' => 'contract.pdf', 'type' => 'application/pdf']]),
        'attachment1' => UploadedFile::fake()->createWithContent('contract.pdf', dqPdf())]);
    $this->post('/webhooks/inbound/sendgrid/inbound-secret-123456', $sendgrid)->assertUnauthorized(); // not the chosen provider
    DB::connection(Connections::control())->table('outside_services')->insert(['tenant_id' => 0, 'service' => 'inbound_email', 'provider' => 'sendgrid', 'mode' => 'live',
        'test_keys' => Crypt::encryptString('{"api_key":"sg"}'), 'live_keys' => Crypt::encryptString('{"api_key":"sg"}'), 'test_contacts' => '', 'created_at' => now(), 'updated_at' => now()]);
    $this->post('/webhooks/inbound/sendgrid/wrong-secret-0000000', $sendgrid)->assertUnauthorized();
    $this->post('/webhooks/inbound/sendgrid/inbound-secret-123456', $sendgrid)->assertOk()->assertJson(['status' => 'created']);

    // Mailgun: signed with the webhook signing key.
    DB::connection(Connections::control())->table('outside_services')->update(['provider' => 'mailgun',
        'live_keys' => Crypt::encryptString('{"api_key":"mg","domain":"inbound.rightally.app","signing_key":"mg-signing-key"}')]);
    $ts = (string) time();
    $mailgun = $form(['recipient' => $address, 'from' => 'pat@acme.test', 'attachment-count' => 1, 'timestamp' => $ts, 'token' => 'tok-1',
        'signature' => hash_hmac('sha256', $ts.'tok-1', 'mg-signing-key'), 'attachment-1' => UploadedFile::fake()->createWithContent('contract.pdf', dqPdf())]);
    $this->post('/webhooks/inbound/mailgun', [...$mailgun, 'signature' => str_repeat('0', 64)])->assertUnauthorized();
    $this->post('/webhooks/inbound/mailgun', $mailgun)->assertOk()->assertJson(['status' => 'created']);
    expect(Deal::query()->count())->toBe(2);
});

it('adds nine more state packs with their standard windows, and the contract always wins', function (): void {
    expect(StatePacks::for('FL')['earnest_money_days'])->toBe(3)->and(StatePacks::for('IL')['attorney_review_business_days'])->toBe(5)
        ->and(StatePacks::summaries())->toHaveKeys(['Florida', 'California', 'Georgia', 'North Carolina', 'Illinois', 'Arizona', 'Colorado', 'Washington', 'New York'])
        ->and(StatePacks::summaries()['California'])->toContain('within 3 business days')->toContain('Inspection deadline: 17 days');

    $deal = Deal::query()->create(['type' => 'buyer', 'status' => 'pre_contract', 'agent_user_id' => $this->agent->id, 'street' => '1 Palm Ave', 'state_code' => 'CA',
        'price_cents' => 90_000_000, 'contract_date' => '2026-06-05', 'created_by' => $this->agent->id]); // a Friday
    $plan = collect(app(Timeline::class)->plan($deal))->keyBy('label');
    expect($plan['Earnest money due']['due_on'])->toBe('2026-06-10') // 3 business days
        ->and($plan['Seller disclosures due']['due_on'])->toBe('2026-06-12')
        ->and($plan['Inspection deadline']['due_on'])->toBe('2026-06-22') // 17 days lands on a Monday
        ->and($plan['Inspection deadline']['why'])->toContain('Check the contract');

    $il = Deal::query()->create(['type' => 'buyer', 'status' => 'pre_contract', 'agent_user_id' => $this->agent->id, 'street' => '2 Lake St', 'state_code' => 'IL',
        'price_cents' => 40_000_000, 'contract_date' => '2026-06-26', 'created_by' => $this->agent->id]); // Friday before July 3 (observed holiday)
    $plan = collect(app(Timeline::class)->plan($il))->keyBy('label');
    expect($plan['Attorney review ends']['due_on'])->toBe('2026-07-06')->and($plan['Inspection deadline']['due_on'])->toBe('2026-07-06');
});
