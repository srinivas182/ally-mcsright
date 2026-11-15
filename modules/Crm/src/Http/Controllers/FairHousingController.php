<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\FairHousingPack;

/** Settings → Fair Housing (specs/grow/12): the monthly audit packs, as PDFs, for owners. */
final class FairHousingController
{
    public function index(Request $request, FairHousingPack $packs): Response
    {
        self::owner($request);

        return Inertia::render('settings/fair-housing', ['months' => $packs->months(), 'current' => $packs->build(now()->format('Y-m'))]);
    }

    public function pdf(Request $request, string $month, FairHousingPack $packs): \Symfony\Component\HttpFoundation\Response
    {
        self::owner($request);
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1, 404);
        app(AuditLogger::class)->record('crm.fair_housing.pack', metadata: ['month' => $month], severity: 'notice');

        return Pdf::loadView('pdf.fair-housing-pack', ['p' => $packs->build($month)])->download("fair-housing-{$month}.pdf");
    }

    private static function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind === UserKind::Owner, 403);

        return $user;
    }
}
