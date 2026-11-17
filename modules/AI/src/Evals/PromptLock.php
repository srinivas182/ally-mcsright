<?php

declare(strict_types=1);

namespace Modules\AI\Evals;

use Modules\AI\Support\PromptRegistry;

/**
 * The prompt lock (AQ1, specs/ai/08): modules/AI/evals.lock.json records each tested prompt's version and the
 * fingerprint of its text. Changing a prompt without raising its version and refreshing the lock fails the build,
 * so every prompt change is a new version that HQ re-tests (php artisan ai:eval --lock).
 */
final class PromptLock
{
    public static function path(): string
    {
        return base_path('modules/AI/evals.lock.json');
    }

    /** @return array<string, array{version: string, sha256: string}> */
    public static function read(): array
    {
        $data = is_file(self::path()) ? json_decode((string) file_get_contents(self::path()), true) : [];

        return is_array($data) ? $data : [];
    }

    /** @return array<string, array{version: string, sha256: string}> what the lock should say now */
    public static function current(PromptRegistry $prompts): array
    {
        $out = [];
        foreach (EvalSet::all() as $set) {
            $t = $prompts->get($set->prompt);
            $out[$set->prompt] = ['version' => $t->version, 'sha256' => hash('sha256', $t->body)];
        }
        ksort($out);

        return $out;
    }

    /**
     * @return list<string> prompts whose text changed without a new version or a refreshed lock
     */
    public static function problems(PromptRegistry $prompts): array
    {
        $lock = self::read();
        $out = [];
        foreach (self::current($prompts) as $key => $now) {
            $was = $lock[$key] ?? null;
            if ($was === null) {
                $out[] = "{$key}: not in the lock. Run php artisan ai:eval --lock.";
            } elseif ($was['sha256'] !== $now['sha256'] && $was['version'] === $now['version']) {
                $out[] = "{$key}: the prompt changed but its version is still {$now['version']}. Raise the version, then run php artisan ai:eval --lock.";
            } elseif ($was !== $now) {
                $out[] = "{$key}: the lock is out of date. Run php artisan ai:eval --lock.";
            }
        }

        return $out;
    }

    public static function write(PromptRegistry $prompts): void
    {
        file_put_contents(self::path(), json_encode(self::current($prompts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
