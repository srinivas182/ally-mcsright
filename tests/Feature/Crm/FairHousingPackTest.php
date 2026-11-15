<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Crm\FairHousing;
use Modules\Crm\FairHousingPack;
use Modules\Crm\FairHousingPackMail;
use Modules\Sites\SiteSetup;

/* Sprint FH1: one Fair Housing check on every channel, logged by channel, and a monthly audit pack (PDF) for the
   broker that lists what was changed and why. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-10 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
});

it('counts every check by channel and logs what it stopped, removed or warned about', function (): void {
    $this->actingAs($this->ann);
    expect(FairHousing::screen('Bright 3 bed home with a big yard.', 'ads'))->toBe([])
        ->and(FairHousing::screen('Perfect for families, in a safe neighborhood!', 'ads', 'blocked', 'Ad: 12 Oak St'))->toBe(['safe neighborhood', 'perfect for families'])
        ->and(FairHousing::screen('x', 'messages'))->toBe([]); // not a message: not counted
    $db = DB::connection(Connections::cell());
    expect($db->table('crm_fair_housing_checks')->where('channel', 'ads')->first(['checked', 'flagged']))->toEqual((object) ['checked' => 2, 'flagged' => 1])
        ->and($db->table('crm_fair_housing_checks')->where('channel', 'messages')->count())->toBe(0);
    $log = $db->table('crm_fair_housing_log')->sole();
    expect($log->channel)->toBe('ads')->and($log->action)->toBe('blocked')->and($log->where)->toBe('Ad: 12 Oak St')->and((int) $log->user_id)->toBe($this->ann->id)
        ->and($log->excerpt)->toBe('Perfect for families, in a safe neighborhood!');
});

it('checks real channels: a funnel headline is stopped and logged under websites', function (): void {
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('aurum', [], $this->owner), true, $this->owner);
    $this->actingAs($this->ann)->post('/funnels', ['kind' => 'moving', 'area' => 'Austin, TX', 'headline' => 'Great for kids in Austin'])->assertSessionHasErrors('headline');
    expect(DB::connection(Connections::cell())->table('crm_fair_housing_log')->sole())->toMatchObject(['channel' => 'websites', 'action' => 'blocked', 'where' => 'Funnel page']);
});

it('builds the monthly pack and gives owners the PDF', function (): void {
    $this->actingAs($this->ann);
    FairHousing::screen('Ideal for families near the church.', 'messages', 'blocked', 'Text');
    FairHousing::screen('Lovely starter home.', 'ailly', 'removed', 'AIlly chat reply');
    auth()->logout();
    FairHousing::screen('An exclusive neighborhood.', 'ai_content', 'removed', 'AI contact brief draft');

    $p = app(FairHousingPack::class)->build('2026-06');
    $byKey = collect($p['channels'])->keyBy('key');
    expect($p['label'])->toBe('June 2026')->and($p['checked'])->toBe(3)->and($p['flagged'])->toBe(2)
        ->and($byKey['messages'])->toMatchArray(['checked' => 1, 'flagged' => 1, 'blocked' => 1])->and($byKey['ailly'])->toMatchArray(['checked' => 1, 'flagged' => 0])
        ->and($p['items'][0])->toMatchArray(['channel' => 'Texts and emails', 'action' => 'blocked', 'phrases' => ['ideal for families', 'church'], 'by' => 'Ann Agent'])
        ->and($p['items'][1]['by'])->toBe('Automatic (AI)');

    $this->actingAs($this->owner)->get('/settings/fair-housing')->assertInertia(fn (Assert $page) => $page->component('settings/fair-housing')
        ->where('current.flagged', 2)->where('months.0.month', '2026-06'));
    $pdf = $this->actingAs($this->owner)->get('/settings/fair-housing/2026-06.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
    $this->actingAs($this->ann)->get('/settings/fair-housing/2026-06.pdf')->assertForbidden();
});

it('emails last month\'s pack to owners on the 1st, with the PDF attached', function (): void {
    $this->actingAs($this->ann);
    FairHousing::screen('Great for kids.', 'templates', 'blocked', 'Message template');
    auth()->logout();
    $this->travelTo(CarbonImmutable::parse('2026-07-01 13:20', 'UTC'));
    $this->artisan('crm:fair-housing-pack')->assertSuccessful();
    Notification::assertSentTo($this->owner, FairHousingPackMail::class, fn (FairHousingPackMail $m): bool => $m->label === 'June 2026' && $m->flagged === 1
        && $m->filename === 'fair-housing-2026-06.pdf' && str_starts_with($m->pdf, '%PDF') && count($m->toMail($this->owner)->rawAttachments) === 1);
    Notification::assertNotSentTo($this->ann, FairHousingPackMail::class);
});
