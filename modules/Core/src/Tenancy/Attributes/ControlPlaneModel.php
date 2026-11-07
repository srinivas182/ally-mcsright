<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Attributes;

use Attribute;

/**
 * Marks a control-plane model that has a tenant_id column but is intentionally not
 * tenant-scoped (e.g. domain routing data, read before any tenant is known).
 * Every other model with tenant_id must use BelongsToTenant (enforced by tests).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ControlPlaneModel {}
