<?php

declare(strict_types=1);

namespace Modules\Transactions\Risk;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Compliance\Checks;
use Modules\Transactions\Models\Deal;
use ZipArchive;

/**
 * The brokerage audit pack (RD1, specs/transactions/31): one ZIP for a state audit or an E&O insurer covering a
 * period: every deal closed in it with its file status (items approved, findings open or dismissed with the reason,
 * who approved), today's risk register, the compliance checks and state rules in force, and a plain summary.
 * Individual deal files (with their audit certificate) stay one click away on each deal.
 */
final class AuditPack
{
    public function build(CarbonImmutable $from, CarbonImmutable $to, User $by): string
    {
        $db = DB::connection(Connections::cell());
        $deals = Deal::query()->where('status', 'closed')->whereBetween('closed_on', [$from->toDateString(), $to->toDateString()])->orderBy('closed_on')->get();
        $agents = User::query()->whereIn('id', $deals->pluck('agent_user_id')->unique()->all() ?: [0])->pluck('first_name', 'id');
        $csv = fn (array $rows): string => implode("\n", array_map(fn (array $r): string => implode(',', array_map(fn ($c): string => '"'.str_replace('"', '""',
            preg_match('/^[=+\-@\t\r]/', (string) $c) === 1 && ! is_numeric($c) ? "'".$c : (string) $c).'"', $r)), $rows))."\n";
        $dealRows = [['Closed', 'Property', 'State', 'Type', 'Agent', 'Price', 'Checklist items', 'Approved', 'Open findings', 'Dismissed findings', 'Locked']];
        $dismissed = [['Closed', 'Property', 'Check', 'Message', 'Dismissed because', 'By']];
        $openTotal = 0;
        foreach ($deals as $d) {
            $items = $db->table('tx_items')->where('deal_id', $d->id)->get(['status']);
            $f = $db->table('tx_findings')->where('deal_id', $d->id)->get(['code', 'status', 'message', 'dismiss_reason', 'dismissed_by']);
            $open = $f->where('status', 'open')->count();
            $openTotal += $open;
            $dealRows[] = [(string) $d->closed_on?->toDateString(), $d->street.', '.$d->city, $d->state_code, $d->type, (string) ($agents[$d->agent_user_id] ?? ''),
                number_format($d->price_cents / 100, 2, '.', ''), (string) $items->count(), (string) $items->where('status', 'approved')->count(), (string) $open,
                (string) $f->where('status', 'dismissed')->count(), $d->locked_at !== null ? 'yes' : 'no'];
            foreach ($f->where('status', 'dismissed') as $x) {
                $dismissed[] = [(string) $d->closed_on?->toDateString(), $d->street, (string) $x->code, (string) $x->message, (string) ($x->dismiss_reason ?? ''),
                    (string) (User::query()->whereKey((int) $x->dismissed_by)->value('first_name') ?? '')];
            }
        }
        $checks = [['Check', 'What it checks', 'On', 'Severity']];
        foreach (Checks::settings() as $code => $s) {
            $checks[] = [$code, Checks::CATALOG[$code][0], $s['enabled'] ? 'yes' : 'no', $s['severity']];
        }
        $name = app(TenantContext::class)->current()->name;
        $summary = "Audit pack for {$name}\nPeriod: {$from->toDateString()} to {$to->toDateString()}\nPrepared: ".CarbonImmutable::now()->toDayDateTimeString()." by {$by->name}\n\n"
            .'Deals closed: '.$deals->count()."\nOpen findings on closed deals: {$openTotal}\nFindings dismissed (with reasons): ".(count($dismissed) - 1)."\n\n"
            ."Files in this pack:\n- deals.csv: every deal closed in the period and its file status\n- dismissed-findings.csv: what was waived and why\n"
            ."- risk-register.csv: every open risk today\n- compliance-checks.csv: the checks and state rules in force\n\nEach deal's full file with its audit certificate can be exported from the deal.\n";

        $path = tempnam(sys_get_temp_dir(), 'audit').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('README.txt', $summary);
        $zip->addFromString('deals.csv', $csv($dealRows));
        $zip->addFromString('dismissed-findings.csv', $csv($dismissed));
        $zip->addFromString('risk-register.csv', RiskRegister::csv());
        $zip->addFromString('compliance-checks.csv', $csv($checks));
        $zip->close();
        app(AuditLogger::class)->record('transactions.audit_pack.exported', metadata: ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'deals' => $deals->count()], severity: 'warning');

        return $path;
    }
}
