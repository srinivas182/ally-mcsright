<?php

declare(strict_types=1);

namespace Modules\Core\Privacy;

use Carbon\CarbonImmutable;
use Closure;

/**
 * Where personal data lives (TR1, specs/core/18). Modules register:
 * - sources: how to find a person's records by email or phone, give them a copy, delete them and stop marketing;
 * - holds: reasons a record must be kept (a transaction file the brokerage must keep by law);
 * - retention kinds: data that can be removed after a number of months.
 */
final class PrivacyRegistry
{
    /** @var array<string, array{label: string, find: Closure(string, ?string): list<array{id: int, label: string}>, export: Closure(int): array<string, mixed>, erase: Closure(int): string, opt_out: Closure(int): void}> */
    private array $sources = [];

    /** @var list<Closure(string, int): ?string> */
    private array $holds = [];

    /** @var array<string, array{label: string, about: string, default: int|null, min: int, purge: Closure(CarbonImmutable): int}> */
    private array $retention = [];

    /**
     * @param  Closure(string, ?string): list<array{id: int, label: string}>  $find  by email and phone
     * @param  Closure(int): array<string, mixed>  $export
     * @param  Closure(int): string  $erase  returns what happened, in plain words
     * @param  Closure(int): void  $optOut
     */
    public function source(string $key, string $label, Closure $find, Closure $export, Closure $erase, Closure $optOut): void
    {
        $this->sources[$key] = ['label' => $label, 'find' => $find, 'export' => $export, 'erase' => $erase, 'opt_out' => $optOut];
    }

    /** @param Closure(string, int): ?string $hold  (source key, record id) => why it must be kept, or null */
    public function hold(Closure $hold): void
    {
        $this->holds[] = $hold;
    }

    /** @param Closure(CarbonImmutable): int $purge  removes data older than the cutoff in the current brokerage; returns how many */
    public function retention(string $kind, string $label, string $about, ?int $defaultMonths, int $minMonths, Closure $purge): void
    {
        $this->retention[$kind] = ['label' => $label, 'about' => $about, 'default' => $defaultMonths, 'min' => $minMonths, 'purge' => $purge];
    }

    /** @return array<string, array{label: string, find: Closure(string, ?string): list<array{id: int, label: string}>, export: Closure(int): array<string, mixed>, erase: Closure(int): string, opt_out: Closure(int): void}> */
    public function sources(): array
    {
        return $this->sources;
    }

    public function heldBecause(string $source, int $id): ?string
    {
        foreach ($this->holds as $hold) {
            if (($why = $hold($source, $id)) !== null) {
                return $why;
            }
        }

        return null;
    }

    /** @return array<string, array{label: string, about: string, default: int|null, min: int, purge: Closure(CarbonImmutable): int}> */
    public function retentionKinds(): array
    {
        return $this->retention;
    }
}
