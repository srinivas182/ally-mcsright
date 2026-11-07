<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Data;

use Modules\Core\Tenancy\Enums\TenantType;

/**
 * Input for provisioning a new tenant (signup, HQ console, tests).
 */
final readonly class ProvisionTenantData
{
    public function __construct(
        public TenantType $type,
        public string $name,
        public string $slug,
        public string $ownerEmail,
        public string $ownerName,
        public string $idempotencyKey,
        public ?string $stateCode = null,
        public ?int $cellId = null,
        public ?int $createdByUserId = null,
    ) {}
}
