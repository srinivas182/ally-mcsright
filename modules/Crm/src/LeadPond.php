<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;

/**
 * Lead ponds (differentiator 22): company leads not contacted within the broker's set number of
 * days return to a shared pond that agents can claim, first come first served, up to a daily
 * limit. Personal-sphere contacts are never moved.
 */
final class LeadPond
{
    public function __construct(private readonly TenantContext $tenants, private readonly Timeline $timeline) {}

    /**
     * @return array{enabled: bool, after_days: int, daily_claims: int}
     */
    public function settings(): array
    {
        $t = $this->tenants->current();

        return ['enabled' => (bool) $t->getAttribute('crm_pond_enabled'), 'after_days' => (int) $t->getAttribute('crm_pond_after_days'), 'daily_claims' => (int) $t->getAttribute('crm_pond_daily_claims')];
    }

    public function configure(bool $enabled, int $afterDays, int $dailyClaims, User $by): void
    {
        if (! $by->isKind(UserKind::Owner, UserKind::Staff)) {
            throw ValidationException::withMessages(['pond' => 'Only owners and staff can change the lead pond.']);
        }
        Tenant::query()->whereKey($this->tenants->id())->update([
            'crm_pond_enabled' => $enabled, 'crm_pond_after_days' => max(1, min(30, $afterDays)), 'crm_pond_daily_claims' => max(1, min(50, $dailyClaims)),
        ]);
        $this->tenants->set($this->tenants->current()->fresh() ?? $this->tenants->current());
    }

    public function sweep(): int
    {
        $settings = $this->settings();
        if (! $settings['enabled']) {
            return 0;
        }

        $cutoff = CarbonImmutable::now()->subDays($settings['after_days']);
        $moved = 0;
        $stale = Contact::query()->where('origin', Origin::Company)->where('lifecycle', Lifecycle::Lead)->whereNotNull('owner_user_id')
            ->whereNull('in_pond_since')->where('owner_assigned_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNull('last_activity_at')->orWhereColumn('last_activity_at', '<', 'owner_assigned_at'))->get();

        foreach ($stale as $contact) {
            app(AuditLogger::class)->asAutomation('lead_pond', $contact->owner_user_id, "Return company leads not contacted within {$settings['after_days']} days", function () use ($contact, $settings): void {
                $contact->forceFill(['owner_user_id' => null, 'in_pond_since' => CarbonImmutable::now()])->save();
                $this->timeline->system($contact, "Moved to the lead pond: no contact within {$settings['after_days']} days");
            });
            $moved++;
        }

        return $moved;
    }

    /**
     * @return Builder<Contact>
     */
    public function pond(): Builder
    {
        return Contact::query()->whereNotNull('in_pond_since')->whereNull('owner_user_id')->where('origin', Origin::Company);
    }

    public function claimsToday(User $user): int
    {
        return Activity::query()->where('type', 'system')->where('user_id', $user->id)->where('body', 'like', 'Claimed from the lead pond%')
            ->where('occurred_at', '>=', CarbonImmutable::now()->startOfDay())->count();
    }

    public function claim(Contact $contact, User $agent): Contact
    {
        if (! $agent->isKind(UserKind::Agent, UserKind::Owner, UserKind::Staff)) {
            throw ValidationException::withMessages(['pond' => 'Only agents can claim leads.']);
        }
        if ($this->claimsToday($agent) >= $this->settings()['daily_claims']) {
            throw ValidationException::withMessages(['pond' => "You have reached today's claim limit."]);
        }

        // First come, first served: only claim if still unowned in the pond.
        $updated = Contact::query()->whereKey($contact->id)->whereNotNull('in_pond_since')->whereNull('owner_user_id')
            ->update(['owner_user_id' => $agent->id, 'owner_assigned_at' => CarbonImmutable::now(), 'in_pond_since' => null]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['pond' => 'Someone claimed this lead first.']);
        }

        $contact->refresh();
        $this->timeline->system($contact, "Claimed from the lead pond by {$agent->name}", $agent->id);

        return $contact;
    }
}
