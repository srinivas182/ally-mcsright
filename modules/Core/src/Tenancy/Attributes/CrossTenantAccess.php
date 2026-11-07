<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Attributes;

use Attribute;

/**
 * Marks a class allowed to read across tenants (HQ reporting, lifecycle jobs). Every use is
 * announced through CrossTenantAccessed so the audit log records it (specs/core/01, 04).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CrossTenantAccess
{
    public function __construct(public string $reason) {}
}
