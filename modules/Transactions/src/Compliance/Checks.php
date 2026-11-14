<?php

declare(strict_types=1);

namespace Modules\Transactions\Compliance;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;

/** The built-in compliance checks, and the brokerage's settings for each (on or off, severity). */
final class Checks
{
    /** @var array<string, array{0: string, 1: string}> code => [what it checks, default severity] */
    public const CATALOG = [
        'checklist.missing' => ['Required checklist documents present for the deal\'s stage', 'blocker'],
        'checklist.needs_fix' => ['Documents sent back for a fix have been fixed', 'blocker'],
        'docs.blank' => ['No blank-looking fields (price, dates, names) in uploaded documents', 'warning'],
        'docs.mismatch' => ['Price, address, closing date and names agree across documents and the deal', 'warning'],
        'docs.wrong_year' => ['Dates in documents are in the right year', 'warning'],
        'docs.no_signature_record' => ['Contracts signed outside RightAlly carry an e-signature record', 'info'],
        'docs.buyer_agreement' => ['A buyer agreement is on file for buyer deals (NAR settlement)', 'blocker'],
        'docs.wire' => ['No wiring or bank instructions in the file', 'warning'],
        'docs.sensitive' => ['Documents with Social Security or bank numbers are shared only as safe copies', 'info'],
        'agent.licence' => ['The agent\'s licence was current for the deal\'s state on the contract date', 'blocker'],
        'agent.eo' => ['The agent\'s E&O cover was current on the contract date', 'warning'],
        'forms.old_edition' => ['Forms were sent on the current edition', 'warning'],
        'forms.owner_override' => ['Ownership mismatches accepted by the brokerage are visible to the reviewer', 'info'],
    ];

    public const SEVERITIES = ['blocker', 'warning', 'info'];

    /**
     * @return array<string, array{enabled: bool, severity: string}>
     */
    public static function settings(): array
    {
        $saved = DB::connection(Connections::cell())->table('tx_compliance_checks')->get()->keyBy('code');
        $out = [];
        foreach (self::CATALOG as $code => [, $severity]) {
            $row = $saved->get($code);
            $out[$code] = ['enabled' => $row === null || (bool) $row->enabled, 'severity' => $row->severity ?? $severity];
        }

        return $out;
    }
}
