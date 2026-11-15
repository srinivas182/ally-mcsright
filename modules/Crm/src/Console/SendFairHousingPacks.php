<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousingPack;
use Modules\Crm\FairHousingPackMail;

/** On the 1st: last month's Fair Housing audit pack, as a PDF, to each brokerage's owners (specs/grow/12). */
#[CrossTenantAccess('Send the monthly Fair Housing audit pack for every brokerage')]
final class SendFairHousingPacks extends Command
{
    protected $signature = 'crm:fair-housing-pack {--month= : YYYY-MM, last month by default}';

    protected $description = 'Email last month\'s Fair Housing audit pack to owners';

    public function handle(TenantContext $tenants): int
    {
        $month = is_string($this->option('month')) && $this->option('month') !== '' ? $this->option('month') : now()->subMonthNoOverflow()->format('Y-m');
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $sent += $tenants->runAs($tenant, function () use ($month): int {
                    $pack = app(FairHousingPack::class)->build($month);
                    $pdf = Pdf::loadView('pdf.fair-housing-pack', ['p' => $pack])->output();
                    $n = 0;
                    foreach (User::query()->where('kind', UserKind::Owner)->where('status', 'active')->get() as $owner) {
                        $owner->notify(new FairHousingPackMail($pack['label'], $pack['checked'], $pack['flagged'], $pdf, "fair-housing-{$month}.pdf"));
                        $n++;
                    }

                    return $n;
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$sent} pack(s) sent.");

        return self::SUCCESS;
    }
}
