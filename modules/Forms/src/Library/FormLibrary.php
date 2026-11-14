<?php

declare(strict_types=1);

namespace Modules\Forms\Library;

/**
 * An association's official form library (for example a state REALTORS® association). Each connector is
 * licensed per association; the brokerage connects with a member number the association verifies.
 */
interface FormLibrary
{
    public function key(): string;

    public function name(): string;

    /** Whether the association requires a verified member (NRDS or association number) to use its forms. */
    public function needsMembership(): bool;

    public function verifyMember(string $memberId, string $lastName): bool;

    /**
     * @return list<array{id: string, name: string, edition: string, category: ?string}>
     */
    public function catalog(): array;

    /**
     * The current edition of one form: the PDF and, where the library provides them, its fields already placed
     * (in RightAlly's format). With no fields, fillable PDF fields are mapped automatically.
     *
     * @return array{pdf: string, edition: string, fields: list<array<string, mixed>>}
     */
    public function download(string $id): array;
}
