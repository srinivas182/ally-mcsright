<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Audit\AuditorAccess;
use Modules\Ledger\Audit\AuditPack;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** FH2 (specs/finance/23): the audit pack, and read-only access for an outside auditor. */
final class AuditController
{
    public function index(Request $request, AuditPack $packs, AuditorAccess $auditors): Response
    {
        Gate::authorize('finance.ledger.export');
        $owner = self::user($request)->kind === UserKind::Owner;

        return Inertia::render('finance/audit', [
            'packs' => $packs->list(),
            'auditors' => $owner ? $auditors->list() : [],
            'password' => $request->session()->get('auditPackPassword'),
            'defaults' => ['from' => CarbonImmutable::today()->subYear()->startOfYear()->toDateString(), 'to' => CarbonImmutable::today()->subYear()->endOfYear()->toDateString()],
            'can' => ['auditors' => $owner],
            'limits' => ['min_days' => AuditorAccess::MIN_DAYS, 'max_days' => AuditorAccess::MAX_DAYS, 'keep_days' => AuditPack::KEEP_DAYS],
        ]);
    }

    public function requestPack(Request $request, AuditPack $packs): RedirectResponse
    {
        Gate::authorize('finance.ledger.export');
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);
        $packs->request(CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'We are preparing the audit pack. It appears here when it is ready.']);

        return back();
    }

    public function download(Request $request, string $pack, AuditPack $packs, TenantContext $tenants): StreamedResponse
    {
        Gate::authorize('finance.ledger.export');
        $p = $packs->ready($pack);
        app(AuditLogger::class)->record('finance.audit_pack.downloaded', metadata: ['pack' => $pack], severity: 'notice');

        return Storage::disk('local')->download((string) $p->path, $tenants->current()->slug.'-audit-pack-'.$p->from_date.'-to-'.$p->to_date.'.zip', ['Cache-Control' => 'no-store']);
    }

    public function password(Request $request, string $pack, AuditPack $packs): RedirectResponse
    {
        Gate::authorize('finance.ledger.export');
        $p = $packs->ready($pack);
        app(AuditLogger::class)->record('finance.audit_pack.password_shown', metadata: ['pack' => $pack], severity: 'notice');

        return back()->with('auditPackPassword', ['pack' => $pack, 'password' => Crypt::decryptString((string) $p->password)]);
    }

    public function grant(Request $request, AuditorAccess $auditors): RedirectResponse
    {
        $user = self::owner($request);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:191'], 'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'],
            'firm' => ['nullable', 'string', 'max:160'], 'days' => ['required', 'integer']]);
        $auditors->grant($data['email'], $data['first_name'], $data['last_name'], (string) ($data['firm'] ?? ''), (int) $data['days'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invitation sent. Their read-only access ends by itself on the date shown.']);

        return back();
    }

    public function revoke(Request $request, string $access, AuditorAccess $auditors): RedirectResponse
    {
        $auditors->revoke($access, self::owner($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Access ended.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private static function owner(Request $request): User
    {
        $user = self::user($request);
        abort_unless($user->kind === UserKind::Owner, 403);

        return $user;
    }
}
