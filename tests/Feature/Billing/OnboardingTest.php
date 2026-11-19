<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Onboarding\TryDemo;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Models\Contact;
use Modules\Sites\SiteSetup;

/*
| MK2: little HQ involvement — "Get set up" for new owners (five steps, ticked off from what is really done; the
| website live with one tap), and "Try it now" with made-up clients and no sign-up.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
});

it('walks a new owner through five steps ticked off from what is really done, with the website live in one tap', function (): void {
    $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->get('/setup')->assertOk()->assertInertia(fn (Assert $p) => $p->component('setup/index')->where('done', 0)->where('total', 5)
        ->where('steps.1.key', 'website')->where('steps.1.done', false));
    $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('setup.done', 0)->where('setup.total', 5));

    $this->actingAs($owner)->post('/setup/website')->assertRedirect('/setup');
    expect(app(SiteSetup::class)->brokerageSite()?->status)->toBe('live');
    app(ManageContacts::class)->create(['first_name' => 'Real', 'email' => 'real@x.test'], $owner);
    $this->actingAs($owner)->get('/setup')->assertInertia(fn (Assert $p) => $p->where('done', 2)->where('steps.1.done', true)->where('steps.3.done', true));

    $agent = User::factory()->create();
    $this->actingAs($agent)->get('/setup')->assertForbidden();
});

it('lets anyone try RightAlly with made-up clients and no sign-up, only when HQ has switched it on', function (): void {
    config(['marketing.hosts' => ['rightally.io']]);
    $this->get('http://rightally.io/try')->assertNotFound();

    config(['core.demo' => true]);
    Cell::factory()->create(['name' => 'cell-a']);
    $to = (string) $this->get('http://rightally.io/try')->assertRedirect()->headers->get('Location');
    $demo = Tenant::query()->where('slug', TryDemo::SLUG)->sole();
    expect($to)->toContain('/try/enter/')->toContain('signature=');

    $this->useTenantHost($demo);
    $path = (string) parse_url($to, PHP_URL_PATH).'?'.parse_url($to, PHP_URL_QUERY);
    $this->get($path)->assertRedirect('/simple');
    $guest = auth()->user();
    expect($guest)->not->toBeNull()->and((string) $guest->email)->toEndWith('@'.TryDemo::DOMAIN);
    app(TenantContext::class)->runAs($demo, fn () => expect(Contact::query()->where('practice', true)->where('owner_user_id', $guest->id)->count())->toBe(5));

    // A changed link is refused; guests go after a day.
    auth()->logout();
    $this->get(str_replace('signature=', 'signature=x', $path))->assertForbidden();
    $this->travel(25)->hours();
    $this->artisan('billing:demo-cleanup')->assertSuccessful();
    app(TenantContext::class)->runAs($demo, fn () => expect(User::query()->where('email', 'like', '%@'.TryDemo::DOMAIN)->where('kind', 'agent')->count())->toBe(0)
        ->and(Contact::query()->withTrashed()->where('practice', true)->count())->toBe(0));
});
