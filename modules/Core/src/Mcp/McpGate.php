<?php

declare(strict_types=1);

namespace Modules\Core\Mcp;

use App\Models\User;

/**
 * Whether MCP is allowed at all for this brokerage (the HQ switch and the owner's switch). The Connect module
 * supplies the real answer; without it, MCP is off.
 */
interface McpGate
{
    public function allowed(User $user): bool;

    /** Whether the brokerage lets AI assistants make changes at all (each person still approves it for their own). */
    public function writesAllowed(User $user): bool;
}
