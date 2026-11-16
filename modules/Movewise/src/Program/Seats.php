<?php

declare(strict_types=1);

namespace Modules\Movewise\Program;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\Package as BillingPackage;
use Modules\Core\Identity\Actions\ManageInvitations;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Actions\ProvisionTenant;
use Modules\Core\Tenancy\Data\ProvisionTenantData;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\TenantContext;
use Modules\Movewise\Models\SeatRequest;
use Modules\Roster\Verification\LicenceVerifier;

/**
 * The free Movewise Agent seat (specs/movewise/00 §6.1, 03): an agent who isn't on RightAlly gets a small RightAlly
 * account of their own (contacts, tasks, inbox and calendar) so the relay, status updates and close tracking work.
 * They ask on Movewise's "For agents" page; HQ approves; RightAlly emails them an invitation to their new account.
 */
final class Seats
{
    /** The features a seat includes (the agent back office; no website, no AI extras). */
    public const FEATURES = ['backoffice.basic'];

    public function __construct(private readonly LicenceVerifier $verifier) {}

    /** @param  array<string, mixed>  $in */
    public function request(array $in, string $ip): SeatRequest
    {
        $key = 'movewise-seat:'.$ip;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many requests. Try again in an hour.']);
        }
        RateLimiter::hit($key, 3600);
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $state = strtoupper((string) ($in['state'] ?? ''));
        $licence = strtoupper(trim((string) ($in['licence_number'] ?? '')));
        $name = fn (string $k): string => mb_substr(trim(strip_tags((string) ($in[$k] ?? ''))), 0, 80);
        $errors = array_filter([
            'first_name' => $name('first_name') === '' ? 'Enter your first name.' : null,
            'last_name' => $name('last_name') === '' ? 'Enter your last name.' : null,
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) === false ? 'Enter a valid email address.' : null,
            'state' => ! array_key_exists($state, (array) config('movewise.launch_states')) ? 'Choose one of Movewise\'s states.' : null,
            'licence_number' => preg_match('/^[A-Z0-9-]{3,40}$/', $licence) !== 1 ? 'Enter your licence number as the state shows it.' : null,
            'brokerage_name' => trim((string) ($in['brokerage_name'] ?? '')) === '' ? 'Enter the brokerage you\'re licensed under.' : null,
            'agree' => ! (bool) ($in['agree'] ?? false) ? 'Tick to agree to be contacted about your request.' : null,
        ]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if (SeatRequest::query()->where('email', $email)->whereIn('status', ['pending', 'approved'])->exists()) {
            throw ValidationException::withMessages(['email' => 'We already have a request from this email. We\'ll be in touch.']);
        }
        $status = 'unchecked';
        if ($this->verifier->configured()) {
            $r = $this->verifier->lookup($state, $licence, $name('last_name'));
            $status = $r['status'] === 'active' && ($r['last_name'] === null || mb_strtolower($r['last_name']) === mb_strtolower($name('last_name'))) ? 'active'
                : ($r['status'] === 'not_found' ? 'not_found' : ($r['status'] === 'active' ? 'mismatch' : 'inactive'));
        }

        return SeatRequest::query()->create(['first_name' => $name('first_name'), 'last_name' => $name('last_name'), 'email' => $email, 'state' => $state,
            'licence_number' => $licence, 'brokerage_name' => mb_substr(trim(strip_tags((string) $in['brokerage_name'])), 0, 160), 'licence_status' => $status]);
    }

    /** HQ approves: a small account of the agent's own, with the seat's features, and an invitation to it. */
    public function approve(SeatRequest $req, User $hq): SeatRequest
    {
        if ($req->status !== 'pending') {
            throw ValidationException::withMessages(['request' => 'This request was already decided.']);
        }
        if (in_array($req->licence_status, ['not_found', 'inactive', 'mismatch'], true)) {
            throw ValidationException::withMessages(['request' => 'State records don\'t show an active licence under this name.']);
        }
        $tenant = app(ProvisionTenant::class)->handle(new ProvisionTenantData(TenantType::Solo, mb_substr($req->first_name.' '.$req->last_name, 0, 80),
            'mw-'.Str::limit(Str::slug($req->first_name.'-'.$req->last_name), 30, '').'-'.strtolower(Str::random(4)), $req->email, $req->first_name.' '.$req->last_name,
            'movewise-seat:'.$req->ulid, $req->state, null, $hq->id));
        $package = BillingPackage::query()->firstOrCreate(['name' => 'Movewise Agent seat'], ['monthly_cents' => 0, 'yearly_cents' => 0, 'ailly_conversations' => 0,
            'features' => self::FEATURES, 'active' => false]);
        $tenant->forceFill(['billing_package_id' => $package->id, 'billing_exception' => true, 'billing_exception_reason' => 'Free Movewise Agent seat'])->save();
        app(TenantContext::class)->runAs($tenant, fn () => app(ManageInvitations::class)->invite($req->email, $req->first_name, $req->last_name, UserKind::Owner, null));
        $req->forceFill(['status' => 'approved', 'tenant_id' => $tenant->id, 'decided_by' => $hq->id, 'decided_at' => CarbonImmutable::now()])->save();

        return $req;
    }

    public function decline(SeatRequest $req, string $note, User $hq): SeatRequest
    {
        if ($req->status !== 'pending') {
            throw ValidationException::withMessages(['request' => 'This request was already decided.']);
        }
        $req->forceFill(['status' => 'declined', 'note' => mb_substr(trim($note), 0, 500) ?: null, 'decided_by' => $hq->id, 'decided_at' => CarbonImmutable::now()])->save();

        return $req;
    }
}
