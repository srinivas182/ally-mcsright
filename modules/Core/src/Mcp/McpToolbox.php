<?php

declare(strict_types=1);

namespace Modules\Core\Mcp;

use App\Models\User;

/**
 * The tools every module offers to people's AI assistants through the RightAlly MCP server (specs/connect/03).
 * A module registers its tools when it boots; each tool is a RightAllyTool, so the guardrails always apply.
 */
final class McpToolbox
{
    /** @var list<class-string<RightAllyTool>> */
    private array $tools = [];

    /** @param class-string<RightAllyTool> $tool */
    public function add(string $tool): void
    {
        if (! in_array($tool, $this->tools, true)) {
            $this->tools[] = $tool;
        }
    }

    /** @return list<class-string<RightAllyTool>> */
    public function all(): array
    {
        return $this->tools;
    }

    /** @var array<string, \Closure(User): array<mixed>> */
    private array $briefs = [];

    /**
     * A section of the daily brief (e.g. "tasks", "key_dates"). The closure gets the person and must use the same
     * access rules as the screens.
     *
     * @param  \Closure(User): array<mixed>  $section
     */
    public function brief(string $name, \Closure $section): void
    {
        $this->briefs[$name] = $section;
    }

    /** @return array<string, \Closure(User): array<mixed>> */
    public function briefs(): array
    {
        return $this->briefs;
    }
}
