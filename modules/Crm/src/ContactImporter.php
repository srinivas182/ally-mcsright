<?php

declare(strict_types=1);

namespace Modules\Crm;

use Carbon\CarbonImmutable;
use Generator;
use Throwable;

/**
 * CSV reading and column matching for contact imports.
 */
final class ContactImporter
{
    public const FIELDS = ['first_name', 'last_name', 'full_name', 'company_name', 'email', 'phone', 'address_line1', 'city', 'state', 'zip', 'birthday', 'tags', 'notes', 'last_contact'];

    private const GUESSES = [
        'first_name' => ['first name', 'firstname', 'first', 'given name'],
        'last_name' => ['last name', 'lastname', 'last', 'surname', 'family name'],
        'full_name' => ['name', 'full name', 'contact name', 'contact'],
        'company_name' => ['company', 'company name', 'organization', 'business'],
        'email' => ['email', 'e mail', 'email address', 'e mail address', 'primary email', 'email 1'],
        'phone' => ['phone', 'mobile', 'cell', 'cell phone', 'mobile phone', 'phone number', 'primary phone', 'phone 1'],
        'address_line1' => ['address', 'street', 'street address', 'address 1', 'address line 1'],
        'city' => ['city', 'town'],
        'state' => ['state', 'st', 'province'],
        'zip' => ['zip', 'zip code', 'zipcode', 'postal code'],
        'birthday' => ['birthday', 'birth date', 'date of birth', 'dob'],
        'tags' => ['tags', 'tag', 'groups', 'labels'],
        'notes' => ['notes', 'note', 'comments', 'background', 'description'],
        'last_contact' => ['last contact', 'last contacted', 'last contact date', 'last activity', 'last activity date', 'last touch'],
    ];

    /**
     * @return list<string>
     */
    public static function headers(string $path): array
    {
        foreach (self::rows($path, true) as $row) {
            return array_values(array_map(fn ($h) => trim((string) $h), $row));
        }

        return [];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string> header => field
     */
    public static function guess(array $headers): array
    {
        $mapping = [];
        foreach ($headers as $header) {
            $normal = mb_strtolower(trim(preg_replace('/[_\-]+/', ' ', $header) ?? ''));
            foreach (self::GUESSES as $field => $names) {
                if (in_array($normal, $names, true) && ! in_array($field, $mapping, true)) {
                    $mapping[$header] = $field;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Data rows (or the header row when $headerOnly), keyed by header, numbered from 2 like a
     * spreadsheet. Handles a UTF-8 byte-order mark and Windows-1252 files.
     *
     * @return Generator<int, array<int|string, string>>
     */
    public static function rows(string $path, bool $headerOnly = false): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            $headers = null;
            $line = 1;
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $cells = array_map(function ($cell): string {
                    $cell = (string) $cell;
                    if (! mb_check_encoding($cell, 'UTF-8')) {
                        $cell = mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
                    }

                    return trim($cell);
                }, $cells);

                if ($headers === null) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]) ?? $cells[0];
                    if ($headerOnly) {
                        yield 1 => $cells;

                        return;
                    }
                    $headers = $cells;

                    continue;
                }

                $line++;
                if (count(array_filter($cells, fn ($c) => $c !== '')) === 0) {
                    continue;
                }
                $row = [];
                foreach ($headers as $i => $header) {
                    $row[$header] = $cells[$i] ?? '';
                }
                yield $line => $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int|string, string>  $row
     * @param  array<string, string>  $mapping
     * @return array<string, string>
     */
    public static function map(array $row, array $mapping): array
    {
        $data = [];
        foreach ($mapping as $header => $field) {
            $value = trim((string) ($row[$header] ?? ''));
            if ($value !== '' && in_array($field, self::FIELDS, true)) {
                $data[$field] = $value;
            }
        }

        if (isset($data['full_name']) && ! isset($data['first_name']) && ! isset($data['last_name'])) {
            $parts = preg_split('/\s+/', $data['full_name']) ?: [];
            $data['last_name'] = count($parts) > 1 ? (string) array_pop($parts) : '';
            $data['first_name'] = implode(' ', $parts);
        }

        return $data;
    }

    public static function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
