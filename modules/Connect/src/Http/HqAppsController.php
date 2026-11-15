<?php

declare(strict_types=1);

namespace Modules\Connect\Http;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;

/** HQ → App demand (specs/connect/00): which coming apps brokerages ask for, to decide what to build next. */
final class HqAppsController
{
    public function index(AppCatalog $catalog): Response
    {
        $rows = DB::connection(Connections::control())->table('hq_app_requests')
            ->selectRaw('app_key, count(*) as brokerages, sum(requests) as requests, max(last_requested_at) as last_at')->groupBy('app_key')->get()->keyBy('app_key');

        $apps = array_map(fn (App $a): array => ['key' => $a->key, 'name' => $a->name, 'category' => App::CATEGORIES[$a->category], 'status' => $a->status,
            'brokerages' => (int) ($rows[$a->key]->brokerages ?? 0), 'requests' => (int) ($rows[$a->key]->requests ?? 0),
            'last_at' => isset($rows[$a->key]) ? (string) $rows[$a->key]->last_at : null], $catalog->all());
        usort($apps, fn (array $x, array $y): int => [$y['brokerages'], $y['requests']] <=> [$x['brokerages'], $x['requests']]);

        return Inertia::render('hq/apps', ['apps' => $apps]);
    }
}
