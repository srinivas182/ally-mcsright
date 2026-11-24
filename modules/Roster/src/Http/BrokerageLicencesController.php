<?php

declare(strict_types=1);

namespace Modules\Roster\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Http\SignupController;
use Modules\Billing\PaymentSettings;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Roster\Brokerage\BrokerageLicences;

/**
 * LV2: Settings › Licences (owners): the brokerage's licences, what the state's records say, and adding or removing
 * one. HQ › Brokerages › a brokerage: HQ marks a licence as checked by hand with the state.
 */
final class BrokerageLicencesController
{
    /**
     * The states RightAlly works in, by name.
     *
     * @return array<string, string>
     */
    private static function states(): array
    {
        return class_exists(SignupController::class) ? SignupController::boardsAndStates()['signupStates'] : [];
    }

    public function index(Request $request, BrokerageLicences $licences): Response
    {
        self::owner($request->user());

        return Inertia::render('settings/licences', ['licences' => $licences->all(), 'states' => self::states(), 'mayPublish' => $licences->mayPublish()]);
    }

    public function add(Request $request, BrokerageLicences $licences): RedirectResponse
    {
        $user = self::owner($request->user());
        $states = self::states();
        $data = $request->validate(['state' => ['required', 'string', 'size:2'], 'number' => ['required', 'string', 'max:40'], 'new' => ['nullable', 'boolean']],
            ['state.*' => 'Choose the state.', 'number.*' => 'Type the licence number.']);
        $state = strtoupper((string) $data['state']);
        if (! isset($states[$state])) {
            return back()->withErrors(['state' => 'Choose one of the states we work in.']);
        }
        $testMode = class_exists(PaymentSettings::class) && app(PaymentSettings::class)->summary()['mode'] === 'test';
        $status = $licences->add($state, $states[$state], (string) $data['number'], $user->name, (bool) ($data['new'] ?? false), $testMode, $user);
        Inertia::flash('toast', ['type' => in_array($status, ['active', 'test'], true) ? 'success' : 'info', 'message' => match ($status) {
            'active', 'test' => "Your {$states[$state]} licence is active. You can now add MLS boards in {$states[$state]}.",
            'expired', 'inactive' => "Saved. The state's records show this licence as {$status}: renew it to add MLS boards in {$states[$state]}.",
            'new' => 'Saved. We will check this new licence with the state and let you know.',
            default => 'Saved. We will check this licence with the state by hand.',
        }]);

        return back();
    }

    public function remove(Request $request, int $licence, BrokerageLicences $licences): RedirectResponse
    {
        $licences->remove($licence, self::owner($request->user()));

        return back();
    }

    /** HQ: a licence checked by hand with the state. */
    public function hqVerify(Request $request, Tenant $tenant, int $licence, TenantContext $tenants): RedirectResponse
    {
        $by = $request->user() ?? abort(403);
        $tenants->runAs($tenant, fn () => app(BrokerageLicences::class)->hqVerify($licence, $by));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Marked as checked with the state.']);

        return back();
    }

    private static function owner(?User $user): User
    {
        abort_unless($user !== null && $user->isKind(UserKind::Owner), 403, 'Only owners manage the brokerage licences.');

        return $user;
    }
}
