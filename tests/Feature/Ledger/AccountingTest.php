<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ledger\Accounting\BankReconciliation;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Money\Money;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* AC1 (specs/finance/20): bills, invoices, and the operating bank reconciliation. */

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-02 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->second = User::factory()->owner()->create(['first_name' => 'Second']);
    $this->agent = User::factory()->create();
    $this->chart = app(ChartOfAccounts::class);
    $this->chart->seed();
    $this->ledger = app(LedgerService::class);
});

function acctBalance(string $code): int
{
    return app(LedgerService::class)->balance(app(ChartOfAccounts::class)->account($code))->cents;
}

it('books bills when entered and when paid, and voids them by reversal', function (): void {
    $this->actingAs($this->agent)->get('/finance/bills')->assertForbidden();
    assertPageContract($this->actingAs($this->owner)->get('/finance/bills'), 'finance/bills');
    $pdf = UploadedFile::fake()->createWithContent('bill.pdf', "%PDF-1.4\n%%EOF");
    $this->actingAs($this->owner)->post('/finance/bills', ['vendor' => 'ACTRIS', 'bill_number' => 'INV-77', 'bill_date' => '2026-06-01', 'due_date' => '2026-06-15',
        'account_code' => '6300', 'amount' => '1,200.00', 'file' => $pdf])->assertSessionHasNoErrors();
    expect(acctBalance('6300'))->toBe(120_000)->and(acctBalance('2400'))->toBe(120_000);
    $this->actingAs($this->owner)->get('/finance/bills')->assertInertia(fn (Assert $p) => $p->where('due_cents', 120_000)->where('due_week_cents', 0)
        ->where('bills.0.vendor', 'ACTRIS')->where('bills.0.has_file', true));
    $ulid = (string) DB::table('acct_bills')->value('ulid');
    $this->actingAs($this->owner)->get("/finance/bills/{$ulid}/file")->assertOk();

    $this->actingAs($this->owner)->post("/finance/bills/{$ulid}/pay", ['paid_on' => '2026-06-02', 'reference' => 'Check 1001'])->assertSessionHasNoErrors();
    expect(acctBalance('2400'))->toBe(0)->and(acctBalance('1000'))->toBe(-120_000);
    $this->actingAs($this->owner)->post("/finance/bills/{$ulid}/pay", ['paid_on' => '2026-06-02'])->assertSessionHasErrors('bill');

    $this->actingAs($this->owner)->post('/finance/bills', ['vendor' => 'Oops', 'bill_date' => '2026-06-01', 'due_date' => '2026-06-30', 'account_code' => '6900', 'amount' => '50'])->assertSessionHasNoErrors();
    $oops = (string) DB::table('acct_bills')->where('vendor', 'Oops')->value('ulid');
    $this->actingAs($this->owner)->post("/finance/bills/{$oops}/void", ['reason' => 'Entered twice'])->assertSessionHasNoErrors();
    expect(acctBalance('6900'))->toBe(0)->and(DB::table('acct_bills')->where('vendor', 'Oops')->value('status'))->toBe('void');
    $this->actingAs($this->owner)->post('/finance/bills', ['vendor' => 'X', 'bill_date' => '2026-06-01', 'due_date' => '2026-06-30', 'account_code' => '1000', 'amount' => '5'])
        ->assertSessionHasErrors('account_code'); // only expense accounts
});

it('issues numbered invoices with a PDF and records payment', function (): void {
    assertPageContract($this->actingAs($this->owner)->get('/finance/invoices'), 'finance/invoices');
    $this->actingAs($this->owner)->post('/finance/invoices', ['customer' => 'Sunrise Builders', 'customer_email' => 'ap@sunrise.test', 'issue_date' => '2026-06-01', 'due_date' => '2026-07-01',
        'account_code' => '4200', 'lines' => [['description' => 'Co-op marketing, May', 'amount' => '750'], ['description' => 'Model home event', 'amount' => '250.50'], ['description' => '', 'amount' => '']]])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/finance/invoices', ['customer' => 'Relo Co', 'issue_date' => '2026-06-01', 'due_date' => '2026-06-15', 'account_code' => '4200',
        'lines' => [['description' => 'Referral fee', 'amount' => '1000']]])->assertSessionHasNoErrors();
    expect(acctBalance('1150'))->toBe(200_050)->and(acctBalance('4200'))->toBe(200_050);
    $this->actingAs($this->owner)->get('/finance/invoices')->assertInertia(fn (Assert $p) => $p->where('invoices.0.number', 2)->where('invoices.1.number', 1)
        ->where('invoices.1.total_cents', 100_050)->where('open_cents', 200_050));
    $first = (string) DB::table('acct_invoices')->where('number', 1)->value('ulid');
    $this->actingAs($this->owner)->get("/finance/invoices/{$first}.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->owner)->post("/finance/invoices/{$first}/paid", ['paid_on' => '2026-06-02'])->assertSessionHasNoErrors();
    expect(acctBalance('1150'))->toBe(100_000)->and(acctBalance('1000'))->toBe(100_050);
    $this->actingAs($this->owner)->post('/finance/invoices', ['customer' => 'X', 'issue_date' => '2026-06-01', 'due_date' => '2026-06-15', 'account_code' => '4200',
        'lines' => [['description' => '', 'amount' => '']]])->assertSessionHasErrors('lines');
});

it('reconciles the operating bank account and needs a second person to approve', function (): void {
    $this->ledger->post(JournalDraft::make('test', 'capital')->effectiveOn(CarbonImmutable::parse('2026-05-01'))->describe('Owner capital')
        ->debit($this->chart->account('1000'), Money::of(1_000_000))->credit($this->chart->account('3000'), Money::of(1_000_000)));
    $this->ledger->post(JournalDraft::make('test', 'rent')->effectiveOn(CarbonImmutable::parse('2026-05-10'))->describe('Rent')
        ->debit($this->chart->account('6000'), Money::of(120_000))->credit($this->chart->account('1000'), Money::of(120_000)));
    $this->ledger->post(JournalDraft::make('test', 'deposit')->effectiveOn(CarbonImmutable::parse('2026-05-30'))->describe('Late deposit')
        ->debit($this->chart->account('1000'), Money::of(50_000))->credit($this->chart->account('4200'), Money::of(50_000)));

    $csv = "Date,Description,Amount\n2026-05-01,DEPOSIT,10000.00\n2026-05-12,CHECK 1001,-1200.00\n2026-05-31,MONTHLY SERVICE FEE,-25.00\n";
    $file = UploadedFile::fake()->createWithContent('may.csv', $csv);
    assertPageContract($this->actingAs($this->owner)->get('/finance/bank?month=2026-05'), 'finance/bank');
    $this->actingAs($this->owner)->post('/finance/bank/import', ['month' => '2026-05', 'ending_balance' => '8,775.00', 'file' => $file])->assertRedirect('/finance/bank?month=2026-05');

    $this->actingAs($this->owner)->get('/finance/bank?month=2026-05')->assertInertia(fn (Assert $p) => $p->where('summary.matched', 2)
        ->where('summary.unmatched_bank.0.description', 'MONTHLY SERVICE FEE')->where('summary.in_transit_cents', 50_000)->where('summary.book_cents', 930_000)
        ->where('summary.difference_cents', -2_500)->where('summary.can_approve', false));
    $fee = (int) DB::table('acct_bank_lines')->where('description', 'MONTHLY SERVICE FEE')->value('id');
    $this->actingAs($this->owner)->post("/finance/bank/lines/{$fee}/add", ['account_code' => '4200'])->assertSessionHasErrors('account_code'); // money out needs an expense
    $this->actingAs($this->owner)->post("/finance/bank/lines/{$fee}/add", ['account_code' => '6700'])->assertSessionHasNoErrors();
    expect(acctBalance('6700'))->toBe(2_500);

    $this->actingAs($this->owner)->get('/finance/bank?month=2026-05')->assertInertia(fn (Assert $p) => $p->where('summary.difference_cents', 0)->where('summary.can_approve', false));
    $this->actingAs($this->owner)->post('/finance/bank/approve', ['month' => '2026-05'])->assertSessionHasErrors('month'); // the preparer
    $this->actingAs($this->second)->get('/finance/bank?month=2026-05')->assertInertia(fn (Assert $p) => $p->where('summary.can_approve', true));
    $this->actingAs($this->second)->post('/finance/bank/approve', ['month' => '2026-05'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/finance/bank/import', ['month' => '2026-05', 'ending_balance' => '1', 'file' => UploadedFile::fake()->createWithContent('x.csv', $csv)])
        ->assertSessionHasErrors('month');
});

it('reads OFX and QFX downloads', function (): void {
    $ofx = "OFXHEADER:100\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>\n<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260512120000<TRNAMT>-1200.00<FITID>A1<NAME>CHECK 1001</STMTTRN>\n"
        ."<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20260501<TRNAMT>10000.00<FITID>A2<NAME>DEPOSIT<MEMO>Owner</STMTTRN>\n</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";
    $lines = BankReconciliation::parseOfx($ofx);
    expect($lines)->toHaveCount(2)->and($lines[0])->toBe(['posted_on' => '2026-05-12', 'description' => 'CHECK 1001', 'amount_cents' => -120000, 'fitid' => 'A1'])
        ->and($lines[1]['description'])->toBe('DEPOSIT Owner');
});

it('reports profit and loss beside last year, and a balance sheet that balances (AC2)', function (): void {
    $post = fn (string $key, string $on, string $dr, string $cr, int $cents) => $this->ledger->post(JournalDraft::make('test', $key)->effectiveOn(CarbonImmutable::parse($on))->describe($key)
        ->debit($this->chart->account($dr), Money::of($cents))->credit($this->chart->account($cr), Money::of($cents)));
    $post('capital', '2025-01-02', '1000', '3000', 5_000_000);
    $post('cd-2025', '2025-03-01', '1000', '4000', 800_000);
    $post('rent-2025', '2025-03-05', '6000', '1000', 200_000);
    $post('cd-2026', '2026-03-01', '1000', '4000', 1_000_000);
    $post('fees-2026', '2026-04-01', '1000', '4100', 150_000);
    $post('rent-2026', '2026-03-05', '6000', '1000', 220_000);
    $post('mls-2026', '2026-04-05', '6300', '2400', 30_000); // a bill not yet paid
    // A per-agent sub-account rolls up into its parent.
    $this->ledger->post(JournalDraft::make('test', 'payable')->effectiveOn(CarbonImmutable::parse('2026-05-01'))->describe('payable')
        ->debit($this->chart->account('1000'), Money::of(10_000))->credit($this->chart->subAccount('2000', 'agent', $this->agent->id), Money::of(10_000)));

    assertPageContract($this->actingAs($this->owner)->get('/finance/statements'), 'finance/statements');
    $this->actingAs($this->owner)->get('/finance/statements?preset=ytd')->assertInertia(fn (Assert $p) => $p
        ->where('pnl.revenue_cents', 1_150_000)->where('pnl.expense_cents', 250_000)->where('pnl.net_cents', 900_000)
        ->where('pnl.prior_revenue_cents', 800_000)->where('pnl.prior_net_cents', 600_000)
        ->where('pnl.revenue.0.code', '4000')->where('pnl.revenue.0.prior_cents', 800_000)
        ->where('balance.balanced', true)->where('balance.liabilities_cents', 40_000)
        ->where('balance.assets_cents', 5_000_000 + 800_000 - 200_000 + 1_000_000 + 150_000 - 220_000 + 10_000)
        ->where('balance.equity', fn ($e) => collect($e)->firstWhere('name', 'Profit not yet closed into equity')['cents'] === 1_500_000));
    $this->actingAs($this->owner)->get('/finance/statements?preset=last_year')->assertInertia(fn (Assert $p) => $p->where('pnl.net_cents', 600_000)->where('pnl.from', '2025-01-01'));
    $this->actingAs($this->owner)->get('/finance/statements.pdf?preset=ytd')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $csv = $this->actingAs($this->owner)->get('/finance/statements.csv?preset=ytd')->assertOk()->streamedContent();
    expect($csv)->toContain('"Net profit",9000.00,6000.00')->toContain('Balance sheet');
    $this->actingAs($this->agent)->get('/finance/statements')->assertForbidden();
});
