<?php

declare(strict_types=1);

namespace Modules\Billing\Onboarding;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\SiteSetup;

/**
 * MK2: "Get set up in about 10 minutes" — the owner's first page after signing up, five big steps, each with one
 * button and a plain sentence, ticked off from what is really done (not from clicking "done"):
 *
 * 1. your logo and colours (the brand kit; the theme is made from them);
 * 2. your website, live today (one tap publishes a ready-made site with starter content);
 * 3. invite your agents (brokerages only);
 * 4. bring your people (do it yourself, or "send us your spreadsheet");
 * 5. connect your MLS (a request HQ approves with the MLS).
 *
 * Nothing here needs RightAlly HQ except the MLS approval and a concierge spreadsheet, which the law or the MLS
 * requires, or the customer asked for.
 */
final class Setup
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{steps: list<array{key: string, title: string, why: string, done: bool, href: string, action: string}>, done: int, total: int}
     */
    public function for(User $owner): array
    {
        $tenantId = (int) $this->tenants->id();
        $solo = $this->tenants->current()->type === TenantType::Solo;
        $cell = DB::connection(Connections::cell());
        $site = app(SiteSetup::class)->brokerageSite();
        $steps = [
            ['key' => 'brand', 'title' => 'Add your logo and colours', 'why' => 'Your website, emails and posts use them. Takes a minute.',
                'done' => Schema::connection(Connections::cell())->hasTable('studio_brand_kits') && $cell->table('studio_brand_kits')->whereNull('user_id')->whereNotNull('logo_path')->exists(),
                'href' => '/studio/brand', 'action' => 'Add logo'],
            ['key' => 'website', 'title' => 'Put your website live', 'why' => 'One tap: a ready-made website with your details, listings search and starter pages. Change anything later.',
                'done' => $site !== null && $site->status === 'live', 'href' => '/setup/website', 'action' => 'Publish my website'],
        ];
        if (! $solo) {
            $steps[] = ['key' => 'agents', 'title' => 'Invite your agents', 'why' => 'Each agent gets an email with their own sign-in link. Add one at a time or from a spreadsheet.',
                'done' => User::query()->where('kind', UserKind::Agent)->exists() || Invitation::query()->whereNull('revoked_at')->exists(), 'href' => '/users', 'action' => 'Invite agents'];
        }
        $steps[] = ['key' => 'people', 'title' => 'Bring your people', 'why' => 'Add your contacts and past clients yourself, or send us your spreadsheet and we will do it for you.',
            'done' => $cell->table('crm_contacts')->where('practice', false)->exists()
                || DB::connection(Connections::control())->table('support_tickets')->where('tenant_id', $tenantId)->where('topic', 'concierge')->exists(),
            'href' => '/contacts?import=1', 'action' => 'Bring my people'];
        $steps[] = ['key' => 'mls', 'title' => 'Connect your MLS', 'why' => 'So your listings and search show on your website. We ask your MLS for you; it usually takes a few days.',
            'done' => DB::connection(Connections::control())->table('mls_authorizations')->where('tenant_id', $tenantId)->exists(), 'href' => '/settings/mls', 'action' => 'Connect MLS'];

        $done = count(array_filter($steps, fn (array $s): bool => $s['done']));

        return ['steps' => $steps, 'done' => $done, 'total' => count($steps)];
    }

    /** One tap: a ready-made website with the brokerage's details, published. */
    public function publishWebsite(User $owner): void
    {
        $sites = app(SiteSetup::class);
        $site = $sites->brokerageSite() ?? $sites->create('modern', array_filter(['area' => (string) ($this->tenants->current()->getAttribute('state_code') ?? ''),
            'phone' => (string) ($owner->phone ?? ''), 'email' => (string) $owner->email]), $owner);
        $sites->publish($site, true, $owner);
    }
}
