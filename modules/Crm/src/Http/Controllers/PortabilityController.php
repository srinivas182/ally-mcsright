<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Crm\Portability\Portability;

/** The portability guarantee (specs/grow/03): download my own people, now or after leaving. */
final class PortabilityController
{
    public function download(Request $request, Portability $portability): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return self::file($portability->csv($user, 'self'), $user);
    }

    /** The emailed link's page: a button, so a mail scanner opening the link uses no download. */
    public function show(string $token, Portability $portability): Response
    {
        $agent = $portability->peek($token);

        return response()->view('crm::portability.take', ['ok' => $agent !== null, 'people' => $agent !== null ? $portability->count($agent) : 0])
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function take(string $token, Portability $portability): Response
    {
        $agent = $portability->redeem($token) ?? abort(404);

        return self::file($portability->csv($agent, 'after leaving'), $agent);
    }

    private static function file(string $csv, User $agent): Response
    {
        $name = 'my-people-'.str($agent->first_name.' '.$agent->last_name)->slug()->value().'-'.now()->format('Y-m-d').'.csv';

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
