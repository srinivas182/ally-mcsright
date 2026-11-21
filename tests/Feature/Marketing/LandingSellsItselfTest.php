<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\Package;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Marketing\ClaimsCheck;
use Modules\Marketing\Website;

/*
| MK1: a website that sells itself — two doors (brokerage or agent), what is included and the prices read live from
| the package builder, a "what you pay today" calculator, and never another company's name or "cheap".
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->team = Package::query()->create(['name' => 'Team', 'audience' => 'brokerage', 'monthly_cents' => 49_900, 'yearly_cents' => 0, 'features' => [], 'active' => true]);
    $this->team->forceFill(['modules' => ['crm', 'website', 'texting', 'transactions']])->save();
    $this->solo = Package::query()->create(['name' => 'Solo', 'audience' => 'agent', 'monthly_cents' => 7_900, 'yearly_cents' => 0, 'features' => [], 'active' => true]);
    $this->solo->forceFill(['modules' => ['crm', 'todays_ten']])->save();
    DB::connection(Connections::control())->table('help_videos')->insert(['path' => '/modules/crm', 'title' => 'CRM in a minute', 'url' => 'https://youtu.be/abcdefghijk',
        'created_at' => now(), 'updated_at' => now()]);
    $this->artisan('marketing:starter')->assertSuccessful();
});

it('opens with two doors, and shows what is included and the prices live from the package builder', function (): void {
    $html = (string) $this->get('http://rightally.io/')->assertOk()->getContent();
    expect($html)->toContain('I run a brokerage')->toContain('href="/for-brokers"')->toContain('href="/for-agents"')
        ->toContain('CRM')->toContain('Website with AIlly')->toContain('In: Team')->toContain('Watch in 1 minute')
        ->not->toContain('Books and trust accounting') // in no live package yet
        ->toContain('$499')->toContain('data-savings=');

    $agents = (string) $this->get('http://rightally.io/for-agents')->assertOk()->getContent();
    expect($agents)->toContain('Today&#039;s 10')->toContain('$79')->toContain('/agents?package='.$this->solo->ulid)->not->toContain('$499');

    // Turn a module on in a package and it appears on the website.
    $this->team->forceFill(['modules' => ['crm', 'website', 'texting', 'transactions', 'accounting']])->save();
    expect((string) $this->get('http://rightally.io/for-brokers')->getContent())->toContain('Books and trust accounting');
});

it('never names another company or calls RightAlly cheap, in pages, titles or posts', function (): void {
    expect(ClaimsCheck::problem('Better than kvCORE'))->toContain('kvcore')
        ->and(ClaimsCheck::problem('The cheapest brokerage software'))->toContain('cheapest')
        ->and(ClaimsCheck::problem('Everything in one place, on one bill'))->toBeNull()
        ->and(ClaimsCheck::problem('Compassionate service'))->toBeNull(); // whole words only

    $site = app(Website::class);
    expect(fn () => $site->saveDraft(null, 'en', 'switch', 'Switch to RightAlly', 'Move over', [['type' => 'text', 'heading' => '', 'body' => 'Leaving Follow Up Boss? Easy.']], $this->staff))
        ->toThrow(ValidationException::class)
        ->and(fn () => $site->saveDraft(null, 'en', 'value', 'The cheap way to run a brokerage', 'x', [['type' => 'text', 'heading' => '', 'body' => 'One bill.']], $this->staff))
        ->toThrow(ValidationException::class)
        ->and(fn () => $site->savePost(null, 'en', 'why', 'Why one system', 'x', 'Dotloop and others are separate.', 'HQ', null, true, $this->staff))
        ->toThrow(ValidationException::class);
});

it('refreshes existing starter pages as drafts, leaving what is live alone', function (): void {
    $home = app(Website::class)->page('en', '');
    DB::connection(Connections::control())->table('marketing_pages')->where('id', $home->id)->update(['title' => 'Our own title']);
    $this->artisan('marketing:starter --refresh')->assertSuccessful();
    expect(app(Website::class)->page('en', '')->title)->toBe('Our own title');
});

it('shows agent seat prices live from billing: with a brokerage and on their own (BR4)', function (): void {
    $this->artisan('billing:starter-prices')->assertSuccessful();
    $this->artisan('marketing:starter', ['--refresh' => true])->assertSuccessful();
    $site = app(Website::class);
    $page = $site->page('en', 'pricing');
    $site->publish((string) $page->ulid, $this->staff);

    $html = (string) $this->get('http://rightally.io/pricing')->assertOk()->getContent();
    expect($html)->toContain('For agents')->toContain('$38')->toContain('$58')->toContain('$98')->toContain('$138')->toContain('$158')->toContain('$198')
        ->toContain('a month with your brokerage')->toContain('month on your own');

    AgentPlan::query()->where('key', 'pro')->update(['floor_cents' => 9_900]);
    expect((string) $this->get('http://rightally.io/pricing')->getContent())->toContain('$99');
});
