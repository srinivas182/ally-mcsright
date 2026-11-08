<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;
use Modules\Core\Tenancy\TenantContext;

/**
 * Users are only ever found inside the current tenant (the User model's tenant scope). Without a
 * tenant (central hosts) nobody can be signed in, instead of the scope throwing.
 */
final class TenantUserProvider extends EloquentUserProvider
{
    public function __construct(Hasher $hasher, string $model, private readonly TenantContext $tenants)
    {
        parent::__construct($hasher, $model);
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->tenants->check() ? parent::retrieveById($identifier) : null;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        return $this->tenants->check() ? parent::retrieveByToken($identifier, $token) : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        return $this->tenants->check() ? parent::retrieveByCredentials($credentials) : null;
    }
}
