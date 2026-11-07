<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Data\ProvisionTenantData;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Events\TenantProvisioned;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\Models\TenantHostRedirect;
use Modules\Core\Tenancy\Slug;

/**
 * Creates a tenant and its platform subdomain in one transaction, then announces it so each
 * module seeds its defaults (specs/core/01). Idempotent by idempotency key.
 */
final class ProvisionTenant
{
    public function __construct(
        private readonly PlaceInCell $placement,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(ProvisionTenantData $data): Tenant
    {
        $existing = Tenant::query()->where('provisioning_key', $data->idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $this->validate($data);
        $cell = $this->placement->handle($data->cellId);

        try {
            $tenant = $this->db->connection(Connections::control())->transaction(function () use ($data, $cell): Tenant {
                $tenant = Tenant::query()->create([
                    'type' => $data->type,
                    'name' => trim($data->name),
                    'slug' => $data->slug,
                    'provisioning_key' => $data->idempotencyKey,
                    'status' => TenantStatus::Provisioning,
                    'cell_id' => $cell->id,
                    'state_code' => $data->stateCode,
                    'created_by_user_id' => $data->createdByUserId,
                ]);

                $tenant->domains()->create([
                    'host' => Host::platformHostFor($data->slug),
                    'kind' => DomainKind::Platform,
                    'is_primary' => true,
                    'verification_status' => VerificationStatus::Verified,
                    'ssl_status' => SslStatus::Active,
                    'verified_at' => now(),
                ]);

                $tenant->status = TenantStatus::Active;
                $tenant->save();

                return $tenant;
            });
        } catch (UniqueConstraintViolationException $e) {
            $raced = Tenant::query()->where('provisioning_key', $data->idempotencyKey)->first();
            if ($raced !== null) {
                return $raced;
            }

            throw ValidationException::withMessages(['slug' => 'This address was just taken. Please choose another.']);
        }

        TenantProvisioned::dispatch($tenant->id, $data->ownerEmail, $data->ownerName, $data->createdByUserId);

        return $tenant;
    }

    private function validate(ProvisionTenantData $data): void
    {
        $errors = [];

        if (trim($data->name) === '' || mb_strlen($data->name) > 150) {
            $errors['name'] = 'Enter a name of up to 150 characters.';
        }

        $slugProblem = Slug::problem($data->slug);
        if ($slugProblem !== null) {
            $errors['slug'] = $slugProblem;
        } elseif ($this->slugTaken($data->slug)) {
            $errors['slug'] = 'This address is already in use. Please choose another.';
        }

        if (filter_var($data->ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors['owner_email'] = 'Enter a valid email address.';
        }

        if (trim($data->ownerName) === '') {
            $errors['owner_name'] = 'Enter the owner\'s name.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function slugTaken(string $slug): bool
    {
        $host = Host::platformHostFor($slug);

        return Tenant::withTrashed()->where('slug', $slug)->exists()
            || TenantDomain::query()->where('host', $host)->exists()
            || TenantHostRedirect::query()->where('host', $host)->where('redirect_until', '>', now())->exists();
    }
}
