<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use RuntimeException;

/**
 * Prompts live in code as Markdown with front matter, so every change is reviewed in a pull
 * request and every request logs the prompt version. Key "crm.lead_reply" maps to
 * {path}/crm/lead_reply.md. Modules register their prompt folders with addPath().
 */
final class PromptRegistry
{
    /** @var list<string> */
    private array $paths = [];

    /** @var array<string, PromptTemplate> */
    private array $loaded = [];

    public function addPath(string $path): void
    {
        $this->paths[] = rtrim($path, '/');
    }

    public function get(string $key): PromptTemplate
    {
        if (isset($this->loaded[$key])) {
            return $this->loaded[$key];
        }

        if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $key) !== 1) {
            throw new RuntimeException("Invalid prompt key [{$key}].");
        }

        $relative = str_replace('.', '/', $key).'.md';

        foreach ($this->paths as $path) {
            $file = $path.'/'.$relative;
            if (is_file($file)) {
                return $this->loaded[$key] = $this->parse($key, (string) file_get_contents($file));
            }
        }

        throw new RuntimeException("Prompt [{$key}] not found.");
    }

    private function parse(string $key, string $content): PromptTemplate
    {
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $m) !== 1) {
            throw new RuntimeException("Prompt [{$key}] needs front matter with a version.");
        }

        preg_match('/^version:\s*(\S+)\s*$/m', $m[1], $version);

        return new PromptTemplate($key, $version[1] ?? throw new RuntimeException("Prompt [{$key}] has no version."), trim($m[2]));
    }
}
