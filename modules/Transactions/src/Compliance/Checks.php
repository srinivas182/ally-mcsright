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
        'docs.signatures' => ['Signature and initial lines are signed on every page', 'warning'],
        'docs.referenced_missing' => ['Addenda the contract marks as attached are in the file', 'warning'],
        'docs.no_signature_record' => ['Contracts signed outside RightAlly carry an e-signature record', 'info'],
        'docs.buyer_agreement' => ['A buyer agreement is on file for buyer deals (NAR settlement)', 'blocker'],
        'docs.wire' => ['No wiring or bank instructions in the file', 'warning'],
        'docs.sensitive' => ['Documents with Social Security or bank numbers are shared only as safe copies', 'info'],
        'agent.licence' => ['The agent\'s licence was current for the deal\'s state on the contract date', 'blocker'],
        'agent.eo' => ['The agent\'s E&O cover was current on the contract date', 'warning'],
        'forms.old_edition' => ['Forms were sent on the current edition', 'warning'],
        'forms.owner_override' => ['Ownership mismatches accepted by the brokerage are visible to the reviewer', 'info'],
        'money.commission' => ['The settlement statement pays the commission the CDA says', 'blocker'],
        'money.cda_stale' => ['The CDA matches the deal\'s current price and commission', 'blocker'],
        'money.receipt' => ['Commission received from title matches what is due (or the difference is accepted)', 'blocker'],
        'money.earnest' => ['Earnest money in trust matches the contract, and is released after closing', 'warning'],
        'wire.changed' => ['Wiring instructions are the same in every document (a change is the classic wire-fraud sign)', 'blocker'],
        'risk.missed' => ['Key dates are not missed', 'blocker'],
        'risk.financing' => ['An appraisal is on file before the financing deadline', 'warning'],
        'risk.inspection' => ['An inspection report or waiver is on file before the inspection deadline', 'warning'],
        'risk.closing_docs' => ['A closing disclosure or settlement statement is on file a week before closing', 'warning'],
        'risk.earnest_late' => ['Earnest money arrives by its due date', 'warning'],
        'wire.title_unverified' => ['The title company on the settlement statement is one verified by call-back', 'warning'],
        // RD1: state rule packs (StatePacks).
        'state.tx_iabs' => ['Texas: Information About Brokerage Services (IABS) is in the file', 'warning'],
        'state.tx_seller_disclosure' => ['Texas: Seller\'s Disclosure Notice is in the file', 'warning'],
        'state.ca_agency' => ['California: agency relationship disclosure (AD) is in the file', 'warning'],
        'state.ca_tds' => ['California: Transfer Disclosure Statement is in the file', 'warning'],
        'state.fl_relationship' => ['Florida: brokerage relationship disclosure is in the file', 'warning'],
        'state.ny_agency' => ['New York: agency disclosure form is in the file', 'warning'],
        'state.ny_fair_housing' => ['New York: Housing and Anti-Discrimination Disclosure is in the file', 'warning'],
        'state.az_spds' => ['Arizona: Seller\'s Property Disclosure Statement is in the file', 'warning'],
        'state.co_brokerage_disclosure' => ['Colorado: Brokerage Disclosure to Buyer or Seller is in the file', 'warning'],
        'state.ms_working_relationships' => ['Mississippi: Working Relationships Disclosure is in the file', 'warning'],
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
            // RD1: state rule packs are off until the brokerage turns its state's rules on.
            $out[$code] = ['enabled' => $row === null ? ! str_starts_with($code, 'state.') : (bool) $row->enabled, 'severity' => $row->severity ?? $severity];
        }

        return $out;
    }
}
