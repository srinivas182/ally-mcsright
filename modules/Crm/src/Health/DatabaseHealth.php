<?php

declare(strict_types=1);

namespace Modules\Crm\Health;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Contact;

/**
 * Database health (DH1, specs/grow/16): how usable a database is, as one score, with each problem counted and one click
 * to the people behind it. For an agent: the contacts they own. For the brokerage: everyone's.
 *
 * Checks (weight): duplicates (1), no email or phone (1), not contacted in 6 months (2), leads with no next step (2),
 * past clients with no closing date, so no home anniversary (1). The score is 100 minus the weighted share of contacts
 * with each problem.
 *
 * LN4 (E24): emails that bounce and phones that don't work (from the email and phone checks), and contacts who may
 * have moved because their home sold on the MLS (1 each).
 */
final class DatabaseHealth
{
    /** key => [label, weight, fix, link] */
    public const CHECKS = [
        'duplicates' => ['Duplicates', 1, 'Merge them', '/contacts/duplicates'],
        'unreachable' => ['No email or phone', 1, 'Add a way to reach them', '/contacts?list=no-way-to-reach'],
        'forgotten' => ['Not contacted in 6 months', 2, 'Check in with them', '/contacts?list=not-contacted-6m'],
        'no_next_step' => ['Leads with no next step', 2, 'Add a task or start a plan', '/contacts?list=no-next-step'],
        'missing_close_date' => ['Past clients with no closing date', 1, 'Add the date for anniversary reminders', '/contacts?list=past-no-date'],
        // LN4 (E24).
        'bad_email' => ['Emails that bounce', 1, 'Ask for a new email', '/contacts?list=bad-email'],
        'bad_phone' => ['Phone numbers that don\'t work', 1, 'Ask for a new number', '/contacts?list=bad-phone'],
        'moved' => ['May have moved (their home sold)', 1, 'Congratulate them and ask for the new address', '/contacts?list=may-have-moved'],
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{score: int, total: int, checks: list<array{key: string, label: string, count: int, fix: string, href: string}>, to_fix: int}
     */
    public function for(?User $agent): array
    {
        $base = fn (): Builder => Contact::query()->where('lifecycle', '!=', 'archived')->when($agent !== null, fn ($q) => $q->where('owner_user_id', $agent?->id));
        $total = $base()->count();
        $counts = [
            'duplicates' => $this->duplicates($agent),
            'unreachable' => self::filter($base(), 'unreachable')->count(),
            'forgotten' => $base()->whereIn('lifecycle', ['lead', 'active', 'past_client', 'sphere'])->where('created_at', '<', CarbonImmutable::now()->subDays(30))
                ->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', CarbonImmutable::now()->subDays(180)))->count(),
            'no_next_step' => self::filter($base(), 'no_next_step')->count(),
            'missing_close_date' => self::filter($base(), 'missing_close_date')->count(),
            'bad_email' => self::filter($base(), 'bad_email')->count(),
            'bad_phone' => self::filter($base(), 'bad_phone')->count(),
            'moved' => self::filter($base(), 'moved')->count(),
        ];
        // LN4: the email, phone and moved checks count toward the score only once there is something to go on (checks
        // made, or a contact flagged), so turning them on never changes a score by itself.
        $applies = ['bad_email' => $counts['bad_email'] > 0 || $base()->whereNotNull('email_checked_at')->exists(),
            'bad_phone' => $counts['bad_phone'] > 0 || $base()->whereNotNull('phone_checked_at')->exists(), 'moved' => $counts['moved'] > 0];
        $weights = 0;
        $weighted = 0.0;
        $checks = [];
        foreach (self::CHECKS as $key => [$label, $weight, $fix, $href]) {
            if ($applies[$key] ?? true) {
                $weights += $weight;
                $weighted += $weight * ($total === 0 ? 0 : min(1, $counts[$key] / $total));
            }
            $checks[] = ['key' => $key, 'label' => $label, 'count' => $counts[$key], 'fix' => $fix, 'href' => $href];
        }

        return ['score' => $total === 0 ? 100 : (int) round(100 * (1 - $weighted / $weights)), 'total' => $total, 'checks' => $checks,
            'to_fix' => count(array_filter($counts, fn (int $n): bool => $n > 0))];
    }

    /**
     * The contacts behind a check (smart lists use it).
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public static function filter(Builder $query, string $check): Builder
    {
        return match ($check) {
            'unreachable' => $query->whereNull('primary_email')->whereNull('primary_phone'),
            'no_next_step' => $query->whereIn('lifecycle', ['lead', 'active'])->whereNull('next_task_at')
                ->whereNotExists(fn ($e) => $e->selectRaw('1')->from('crm_plan_enrollments')->whereColumn('crm_plan_enrollments.contact_id', 'crm_contacts.id')->where('crm_plan_enrollments.status', 'active')),
            'missing_close_date' => $query->where('lifecycle', 'past_client')->whereNull('closed_on'),
            'bad_email' => $query->whereNotNull('primary_email')->where('email_status', 'invalid'),
            'bad_phone' => $query->whereNotNull('primary_phone')->where('phone_type', 'invalid'),
            'moved' => $query->whereNotNull('moved_on'),
            default => $query,
        };
    }

    /** Contacts that share an email or phone with an earlier contact. */
    private function duplicates(?User $agent): int
    {
        $n = 0;
        foreach (['primary_email', 'primary_phone'] as $field) {
            $groups = DB::connection(Connections::cell())->table('crm_contacts')->where('tenant_id', $this->tenants->id())->whereNull('deleted_at')
                ->where('lifecycle', '!=', 'archived')->whereNotNull($field)->when($agent !== null, fn ($q) => $q->where('owner_user_id', $agent?->id))
                ->groupBy($field)->havingRaw('count(*) > 1')->selectRaw('count(*) - 1 as extra');
            $n += (int) DB::connection(Connections::cell())->query()->fromSub($groups, 'dupes')->sum('extra');
        }

        return $n;
    }
}
