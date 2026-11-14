<?php

declare(strict_types=1);

namespace Modules\Sites\Rendering;

use Modules\Sites\Models\Site;

/**
 * Which website a request is on (specs/websites/09): the brokerage's, or one agent's — either at
 * /agents/{slug} on the brokerage's address (prefix "/agents/{slug}") or on the agent's own domain (no prefix).
 * On an agent's website the header, contact details and lead routing are the agent's; the brokerage's name,
 * licence and the compliance notices stay in the footer.
 */
final class SiteContext
{
    private ?Site $agent = null;

    private string $prefix = '';

    public function enterAgent(Site $agent, string $prefix): void
    {
        $this->agent = $agent;
        $this->prefix = rtrim($prefix, '/');
    }

    /** Back to the brokerage's website (every request starts here). */
    public function reset(): void
    {
        $this->agent = null;
        $this->prefix = '';
    }

    public function agent(): ?Site
    {
        return $this->agent;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** A link to one of the current website's own pages. */
    public function url(string $path): string
    {
        return $this->prefix === '' ? $path : $this->prefix.($path === '/' ? '' : $path);
    }
}
