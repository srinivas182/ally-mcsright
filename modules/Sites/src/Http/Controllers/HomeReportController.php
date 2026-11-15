<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Funnels\HomeReports;
use Modules\Sites\Models\HomeReport;

/** The one-click stop for the monthly home-value report (signed link; a POST from mail apps stops it at once). */
final class HomeReportController
{
    public function stop(Request $request, string $report, HomeReports $reports, TenantContext $tenants): Response
    {
        abort_unless($request->hasValidSignature(false), 403);
        $row = HomeReport::query()->where('ulid', $report)->firstOrFail();
        if ($request->isMethod('post') && $row->unsubscribed_at === null) {
            $reports->unsubscribe($row);
        }

        return response()->view('crm::alerts.stop', ['done' => $request->isMethod('post') || $row->unsubscribed_at !== null, 'name' => 'Monthly home value for '.$row->address,
            'brokerage' => $tenants->current()->name, 'action' => $request->getRequestUri()])->header('X-Robots-Tag', 'noindex');
    }
}
