<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\Can;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Closing\Appointments;
use Modules\Transactions\Closing\Vendors;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Models\Deal;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Closing logistics, the agent's side (specs/ai/02, DQ5b): appointments, closing time and place, preferred vendors. */
final class ClosingController
{
    public function book(Request $request, Deal $deal, Appointments $appointments): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $d = $request->validate(['kind' => ['required', Rule::in(array_keys(Appointments::KINDS))], 'title' => ['nullable', 'string', 'max:120'], 'starts_at' => ['required', 'date', 'after:now'],
            'vendor' => ['nullable', 'string', 'size:26'], 'vendor_name' => ['nullable', 'string', 'max:160'], 'vendor_email' => ['nullable', 'email', 'max:254'], 'vendor_phone' => ['nullable', 'string', 'max:30']]);
        $appointments->book($deal, $d, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => ($d['vendor_email'] ?? $d['vendor'] ?? null) !== null ? 'Booked. The vendor can confirm or suggest another time.' : 'Booked.']);

        return back();
    }

    public function update(Request $request, Deal $deal, string $appointment, string $action, Appointments $appointments): RedirectResponse
    {
        $appointments->update($deal, $appointment, $action, self::authorise($request, $deal));

        return back();
    }

    public function details(Request $request, Deal $deal): RedirectResponse
    {
        self::authorise($request, $deal);
        $d = $request->validate(['closing_time' => ['nullable', 'date_format:H:i'], 'closing_place' => ['nullable', 'string', 'max:300']]);
        $deal->forceFill(['closing_time' => $d['closing_time'] ?? null, 'closing_place' => ($d['closing_place'] ?? '') !== '' ? $d['closing_place'] : null])->save();
        app(AuditLogger::class)->record('transactions.closing.details', $deal, label: $deal->street);

        return back();
    }

    public function vendors(Request $request): Response
    {
        $user = self::user($request);

        return Inertia::render('deals/vendors', ['vendors' => Vendors::all(), 'categories' => Vendors::CATEGORIES, 'canEdit' => Can::manage($user, 'transactions.templates')]);
    }

    public function saveVendor(Request $request, Vendors $vendors, ?string $vendor = null): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Can::manage($user, 'transactions.templates'), 403);
        $d = $request->validate(['category' => ['required', Rule::in(array_keys(Vendors::CATEGORIES))], 'name' => ['required', 'string', 'max:160'], 'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:30'], 'website' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:300'], 'show_clients' => ['boolean']]);
        $vendors->save($vendor, $d, $user);

        return back();
    }

    public function removeVendor(Request $request, string $vendor, Vendors $vendors): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Can::manage($user, 'transactions.templates'), 403);
        $vendors->remove($vendor, $user);

        return back();
    }

    /** The vendor's page: confirm, or suggest another time (no account; the link is the key). */
    public function vendorPage(Request $request, string $token, Appointments $appointments): HttpResponse
    {
        $a = $appointments->forVendor($token);
        $deal = $a !== null ? Deal::query()->whereKey((int) $a->deal_id)->first() : null;
        $tz = app(TenantContext::class)->current()->timezone ?: 'America/Chicago';
        $response = Inertia::render('public/appointment', ['valid' => $a !== null, 'done' => (string) $request->query('done', ''),
            'appointment' => $a !== null && $deal !== null ? ['title' => (string) $a->title, 'street' => $deal->street, 'city' => $deal->city,
                'starts_at' => CarbonImmutable::parse((string) $a->starts_at)->setTimezone($tz)->format('l, F j, Y \a\t g:i A T'), 'status' => (string) $a->status] : null,
        ])->toResponse($request);
        $response->headers->add(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store']);

        return $response;
    }

    public function vendorAnswer(Request $request, string $token, Appointments $appointments): RedirectResponse
    {
        $d = $request->validate(['confirm' => ['required', 'boolean'], 'proposed' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:500']]);
        $appointments->answer($token, (bool) $d['confirm'], $d['proposed'] ?? null, $d['note'] ?? null);

        return redirect('/appointment/'.$token.'?done='.($d['confirm'] ? 'confirmed' : 'suggested'));
    }

    private static function authorise(Request $request, Deal $deal): User
    {
        $user = self::user($request);
        abort_unless(DealAccess::canSee($user, $deal), 403);

        return $user;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
