<?php

declare(strict_types=1);

namespace Modules\Forms\Library;

use FPDF;

/**
 * A stand-in association library for development and tests until association licences are signed: two forms
 * with editions that can be changed to simulate a new release. Member numbers are 9 digits (NRDS format).
 */
final class TestLibrary implements FormLibrary
{
    /** @var array<string, array{name: string, edition: string, category: string, extra: string}> */
    public static array $forms = [
        'listing' => ['name' => 'Residential Listing Agreement', 'edition' => '11-2025', 'category' => 'Listing', 'extra' => ''],
        'seller-disclosure' => ['name' => "Seller's Disclosure Notice", 'edition' => '01-2026', 'category' => 'Disclosure', 'extra' => ''],
    ];

    public function key(): string
    {
        return 'test';
    }

    public function name(): string
    {
        return 'Sample association library (test)';
    }

    public function needsMembership(): bool
    {
        return true;
    }

    public function verifyMember(string $memberId, string $lastName): bool
    {
        return preg_match('/^\d{9}$/', $memberId) === 1 && $memberId !== '000000000' && trim($lastName) !== '';
    }

    public function catalog(): array
    {
        $out = [];
        foreach (self::$forms as $id => $f) {
            $out[] = ['id' => $id, 'name' => $f['name'], 'edition' => $f['edition'], 'category' => $f['category']];
        }

        return $out;
    }

    public function download(string $id): array
    {
        $f = self::$forms[$id] ?? throw new \RuntimeException('Form not in the library.');
        $pdf = new FPDF('P', 'pt', 'Letter');
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Text(72, 60, strtoupper($f['name']));
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Text(72, 80, 'Edition '.$f['edition']);
        $pdf->Text(72, 120, '1. The property at the address below is listed for the price shown.');
        $pdf->Text(72, 140, '2. The listing ends on the date shown unless extended in writing.');
        if ($f['extra'] !== '') {
            $pdf->Text(72, 160, $f['extra']);
        }

        return ['pdf' => $pdf->Output('S'), 'edition' => $f['edition'], 'fields' => [
            ['key' => 'address', 'type' => 'text', 'page' => 1, 'x' => 72, 'y' => 200, 'w' => 400, 'h' => 14, 'bind' => 'deal.address', 'label' => 'Property address'],
            ['key' => 'price', 'type' => 'text', 'page' => 1, 'x' => 72, 'y' => 230, 'w' => 200, 'h' => 14, 'bind' => 'deal.price', 'label' => 'Price'],
            ['key' => 'seller_sig', 'type' => 'signature', 'page' => 1, 'x' => 72, 'y' => 650, 'w' => 200, 'h' => 20, 'role' => 'seller1', 'label' => 'Seller signature'],
            ['key' => 'agent_sig', 'type' => 'signature', 'page' => 1, 'x' => 320, 'y' => 650, 'w' => 200, 'h' => 20, 'role' => 'listing_agent', 'label' => 'Agent signature'],
        ]];
    }
}
