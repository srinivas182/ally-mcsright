<?php

declare(strict_types=1);

namespace Modules\AI\Support;

/**
 * Validates decoded JSON against a small, predictable subset of JSON Schema:
 * type (object, array, string, number, integer, boolean), properties, required, items, enum.
 */
final class JsonShape
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> problems, empty when valid
     */
    public function validate(mixed $data, array $schema, string $path = '$'): array
    {
        $errors = [];
        $type = $schema['type'] ?? null;

        if (is_string($type) && ! $this->isType($data, $type)) {
            return ["{$path} must be {$type}"];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $errors[] = "{$path} must be one of ".implode(', ', array_map('strval', $schema['enum']));
        }

        if ($type === 'object' && is_array($data)) {
            foreach ((array) ($schema['required'] ?? []) as $name) {
                if (! array_key_exists((string) $name, $data)) {
                    $errors[] = "{$path}.{$name} is required";
                }
            }
            foreach ((array) ($schema['properties'] ?? []) as $name => $child) {
                if (array_key_exists((string) $name, $data) && is_array($child)) {
                    /** @var array<string, mixed> $child */
                    array_push($errors, ...$this->validate($data[$name], $child, "{$path}.{$name}"));
                }
            }
        }

        if ($type === 'array' && is_array($data) && isset($schema['items']) && is_array($schema['items'])) {
            /** @var array<string, mixed> $items */
            $items = $schema['items'];
            foreach (array_values($data) as $i => $item) {
                array_push($errors, ...$this->validate($item, $items, "{$path}[{$i}]"));
            }
        }

        return $errors;
    }

    private function isType(mixed $data, string $type): bool
    {
        return match ($type) {
            'object' => is_array($data) && ($data === [] || ! array_is_list($data)),
            'array' => is_array($data) && array_is_list($data),
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            default => true,
        };
    }
}
