<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Ads\Studio\Canva\CanvaApi;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;

/**
 * Canva's one return address for every brokerage (specs/grow/13, C7d-3): checks Canva's signed token, then sends
 * the person back to their brokerage's website, where the signed-in owner of the session brings the design in.
 */
#[CrossTenantAccess('Canva returns everyone to one address; each is sent back to the brokerage website the session started on')]
final class CanvaReturnController
{
    public function __invoke(Request $request, CanvaApi $api): RedirectResponse
    {
        $claims = $api->returned((string) $request->query('correlation_jwt', ''));
        $state = $claims !== null ? strtoupper($claims['correlation_state']) : '';
        $row = preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $state) === 1 ? DB::connection(Connections::control())->table('canva_returns')->where('state', $state)->first() : null;
        abort_if($row === null || ! is_string($row->host), 400, 'Coming back from Canva did not work. Open Ally Studio and choose "Bring it back" on your Canva design.');

        return redirect()->away('https://'.$row->host.'/studio/canva/'.$state.'/back');
    }
}
