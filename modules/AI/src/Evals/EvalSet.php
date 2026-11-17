<?php

declare(strict_types=1);

namespace Modules\AI\Evals;

use RuntimeException;

/**
 * A prompt's test set (AQ1, specs/ai/08): realistic cases, what a good answer must (and must never) do, and a
 * reviewed example answer ("golden"). Kept next to the prompt as modules/{Module}/evals/{area}/{name}.json.
 */
final readonly class EvalSet
{
    /**
     * @param  list<array{name: string, inputs: array<string, string>, context: list<array{type: string, id: string, fields: array<string, string>}>, untrusted: list<string>, expect: array<string, mixed>, golden: string}>  $cases
     * @param  array<string, mixed>|null  $schema
     */
    public function __construct(
        public string $prompt,
        public string $feature,
        public array $cases,
        public ?array $schema,
        public string $path,
    ) {}

    public static function load(string $path): self
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_string($data['prompt'] ?? null) || ! is_string($data['feature'] ?? null) || ! is_array($data['cases'] ?? null)) {
            throw new RuntimeException("Eval set {$path} needs prompt, feature and cases.");
        }
        $cases = [];
        foreach ($data['cases'] as $i => $c) {
            if (! is_array($c) || ! is_string($c['name'] ?? null) || ! is_string($c['golden'] ?? null)) {
                throw new RuntimeException("Eval set {$path} case {$i} needs a name and a golden answer.");
            }
            $cases[] = [
                'name' => $c['name'],
                'inputs' => array_map('strval', (array) ($c['inputs'] ?? [])),
                'context' => array_values(array_map(fn (array $r): array => ['type' => (string) $r['type'], 'id' => (string) $r['id'],
                    'fields' => array_map('strval', (array) ($r['fields'] ?? []))], (array) ($c['context'] ?? []))),
                'untrusted' => array_values(array_map('strval', (array) ($c['untrusted'] ?? []))),
                'expect' => (array) ($c['expect'] ?? []),
                'golden' => $c['golden'],
            ];
        }

        return new self($data['prompt'], $data['feature'], $cases, isset($data['schema']) && is_array($data['schema']) ? $data['schema'] : null, $path);
    }

    /**
     * Every eval set in the modules.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        $out = [];
        foreach (glob(base_path('modules/*/evals/*/*.json')) ?: [] as $file) {
            $out[] = self::load($file);
        }
        usort($out, fn (self $a, self $b): int => strcmp($a->prompt, $b->prompt));

        return $out;
    }

    /** @return list<string> every prompt key in the modules, e.g. "crm.reply_draft" */
    public static function promptKeys(): array
    {
        $keys = [];
        foreach (glob(base_path('modules/*/prompts/*/*.md')) ?: [] as $file) {
            $keys[] = basename(dirname($file)).'.'.basename($file, '.md');
        }
        sort($keys);

        return $keys;
    }
}
