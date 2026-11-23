<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Affiliates\Affiliates;
use Modules\Billing\HealthCheck\HealthChecks;
use Modules\Billing\Models\Package;
use Modules\Billing\ProductFeatures;
use Modules\Billing\Signup\LicenceCheck;
use Modules\Billing\Signup\Signups;
use Modules\Core\Security\BotProtection;
use Modules\Core\Support\Connections;
use Modules\Sites\Content\DemoPhotos;
use Modules\Sites\Content\StarterSite;
use Modules\Sites\Support\Designs;
use stdClass;

/**
 * SU2: signup in four steps, each saved when they press Continue: 1. you, your address and your MLS boards,
 * 2. package, 3. website design, 4. review and pay. The draft is remembered in this browser; a reminder's signed
 * link brings it back on any device, at the step they stopped. Brokerages sign up at /start, solo agents at /agents.
 */
final class SignupWizardController
{
    public function brokerage(Request $request, Signups $signups): HttpResponse|RedirectResponse
    {
        return $this->show($request, $signups, 'brokerage');
    }

    public function agent(Request $request, Signups $signups): HttpResponse|RedirectResponse
    {
        return $this->show($request, $signups, 'solo');
    }

    private function show(Request $request, Signups $signups, string $type): HttpResponse|RedirectResponse
    {
        $base = $type === 'solo' ? '/agents' : '/start';
        // A reminder's signed link: carry on with that signup here, at the step they stopped.
        if (is_string($request->query('resume')) && $request->hasValidSignature()) {
            $row = Signups::find($request->query('resume'));
            if ($row !== null && in_array($row->status, ['draft', 'pending'], true) && $row->type === $type) {
                $request->session()->put(self::key($type), (string) $row->ulid);

                return redirect($base.'?step='.min(4, (int) $row->step).'&back=1');
            }
        }
        $draft = self::current($request, $type);
        $reached = $draft !== null ? max(1, min(4, (int) $draft->step)) : 1;
        $step = max(1, min($reached, (int) $request->query('step', $reached)));
        $check = $type === 'brokerage' && is_string($request->query('check')) ? HealthChecks::byToken($request->query('check')) : null;
        $audience = $type === 'solo' ? 'agent' : 'brokerage';
        $packages = Package::offered($audience);
        $chosenPackage = $draft !== null && $draft->package_id !== null ? Package::query()->whereKey((int) $draft->package_id)->value('ulid') : null;
        if ($chosenPackage === null && is_string($request->query('package')) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $request->query('package')) === 1) {
            $chosenPackage = $request->query('package');
            $request->session()->put(self::key($type).'.package', $chosenPackage); // SG1: chosen on the website's price page
        }
        $chosenPackage ??= $request->session()->get(self::key($type).'.package');

        return response()->view($type === 'solo' ? 'billing::signup.agents' : 'billing::signup.start', [
            'type' => $type, 'base' => $base, 'step' => $step, 'reached' => $reached, 'draft' => $draft, 'steps' => Signups::STEPS,
            'packages' => $packages, 'features' => ProductFeatures::all(), 'open' => $signups->open($audience),
            'cancelled' => $request->boolean('cancelled'), 'resumed' => $request->boolean('back'),
            'prefill' => self::prefill($draft, $check, $type, is_string($request->query('check')) ? $request->query('check') : ''),
            'prefillBoards' => $draft !== null ? array_values(array_filter((array) json_decode((string) ($draft->idx_boards ?? '[]'), true), 'is_string')) : [],
            'chosen' => is_string($chosenPackage) ? $chosenPackage : null, 'interval' => $draft?->interval,
            // LP1: full website designs with an MLS board; landing page designs without one.
            'landingOnly' => $draft !== null && Signups::landingOnly($draft),
            'designs' => collect((array) config('sites.templates'))->filter(fn (array $t, string $k): bool => Designs::isLanding($k) === ($draft !== null && Signups::landingOnly($draft)))
                ->map(fn (array $t, string $k): array => ['key' => $k, 'name' => (string) $t['name'], 'landing' => Designs::isLanding($k),
                    'description' => (string) ($t['description'] ?? ''), 'theme' => (string) ($t['theme'] ?? '#ffffff'), 'brand' => (array) ($t['brand'] ?? []),
                    'font' => trim((string) strtok((string) ($t['fonts'] ?? 'Inter'), '+')), 'css' => (string) ($t['css'] ?? ''),
                    // SU4: a real thumbnail of the design: its banner photo, its layout and three sample homes.
                    'layout' => (string) ($t['layout'] ?? 'hdr-solid hero-full'), 'hero' => DemoPhotos::url(StarterSite::heroPhoto($k), 'card'),
                    'homes' => [DemoPhotos::url(DemoPhotos::pick('exterior_luxury', 2), 'thumb'), DemoPhotos::url(DemoPhotos::pick('exterior_family', 3), 'thumb'), DemoPhotos::url(DemoPhotos::pick('exterior_coastal', 5), 'thumb')]])->values()->all(),
            'template' => $draft?->template,
            'marketArea' => $draft !== null ? Signups::marketArea((string) ($draft->idx_boards ?? '[]'), $draft->state) : '',
            'marketState' => $draft !== null ? Signups::marketState((string) ($draft->idx_boards ?? '[]'), $draft->state) : null,
            'canonical' => 'https://'.config('core.hq_host').$base,
            'review' => $draft !== null && $step === 4 ? self::review($draft) : null,
            'boardExtra' => $draft !== null ? ['cents' => self::review($draft)['extra_cents'], 'count' => max(0, count(self::review($draft)['boards']) - 1)] : null,
        ] + SignupController::boardsAndStates());
    }

    /** Step 1: you, your address and your boards. */
    public function about(Request $request, Signups $signups, string $type): RedirectResponse
    {
        abort_if(filled($request->input('fax')), 422);
        $solo = $type === 'solo';
        $data = $request->validate($solo ? [
            'name' => ['required', 'string', 'max:120'], 'business' => ['nullable', 'string', 'max:150'], 'email' => ['required', 'email:rfc', 'max:190'],
        ] : [
            'brokerage' => ['required', 'string', 'max:150'], 'owner_name' => ['required', 'string', 'max:120'], 'owner_email' => ['required', 'email:rfc', 'max:190'],
            'agents' => ['nullable', 'integer', 'min:1', 'max:20000'], 'check' => ['nullable', 'string', 'size:40'],
        ] + ['phone' => ['required', 'string', 'max:30', 'regex:/^[0-9 ()+.\-]{7,30}$/'], 'slug' => ['required', 'string', 'max:40'], 'state' => ['nullable', 'string', 'size:2', 'alpha'], 'boards' => ['nullable', 'array', 'max:20'], 'boards.*' => ['string', 'size:26']]);
        // LV1: at least one state where they hold an active licence, with its number, checked in the state's records.
        // LP1: boards are optional; without one the website is a landing page until a board is added.
        $states = SignupController::boardsAndStates()['signupStates'];
        $request->validate(['licence_states' => ['required', 'array', 'min:1', 'max:60'], 'licence_states.*' => ['string', 'size:2', Rule::in(array_keys($states))],
            'licence_numbers' => ['nullable', 'array'], 'licence_numbers.*' => ['nullable', 'string', 'max:40']],
            ['licence_states.required' => 'Tick at least one state where you hold an active real estate licence.', 'licence_states.min' => 'Tick at least one state where you hold an active real estate licence.']);
        $chosen = array_values(array_unique($data['boards'] ?? []));
        $data['boards'] = $chosen;
        $owner = $solo ? (string) $data['name'] : (string) $data['owner_name'];
        $licences = [];
        $errors = [];
        foreach (array_values(array_unique((array) $request->input('licence_states'))) as $st) {
            $number = trim((string) ($request->input('licence_numbers.'.$st) ?? ''));
            if ($number === '') {
                $errors['licence_numbers.'.$st] = 'Type your '.$states[$st].' licence number.';

                continue;
            }
            $result = app(LicenceCheck::class)->check($st, $states[$st], $number, $owner);
            if ($result['error'] !== null) {
                $errors['licence_numbers.'.$st] = $result['error'];

                continue;
            }
            unset($result['error']);
            $licences[] = $result;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $name = $solo ? trim((string) $data['name']) : trim((string) $data['owner_name']);
        $check = ! $solo && isset($data['check']) ? HealthChecks::byToken($data['check']) : null;
        $draft = $signups->saveAbout(self::current($request, $type)?->ulid, [
            'brokerage' => $solo ? (trim((string) ($data['business'] ?? '')) !== '' ? trim((string) $data['business']) : $name) : (string) $data['brokerage'],
            'slug' => (string) $data['slug'], 'owner_name' => $name, 'owner_email' => (string) ($solo ? $data['email'] : $data['owner_email']),
            'state' => $data['state'] ?? null, 'agents' => $solo ? 1 : (isset($data['agents']) ? (int) $data['agents'] : null), 'boards' => $chosen, 'licences' => $licences, 'phone' => trim((string) $data['phone']),
            'health_check_id' => $check !== null ? (int) $check->id : null, 'ref' => is_string($request->cookie(Affiliates::COOKIE)) ? $request->cookie(Affiliates::COOKIE) : null,
        ], $type);
        $request->session()->put(self::key($type), $draft);

        return redirect(($solo ? '/agents' : '/start').'?step=2#signup');
    }

    /** Step 2: package. */
    public function package(Request $request, Signups $signups, string $type): RedirectResponse
    {
        $data = $request->validate(['package' => ['required', 'string', 'size:26'], 'interval' => ['required', 'in:month,year']]);
        $signups->savePackage(self::require($request, $type), (string) $data['package'], (string) $data['interval']);

        return redirect(($type === 'solo' ? '/agents' : '/start').'?step=3#signup');
    }

    /** Step 3: website design. */
    public function design(Request $request, Signups $signups, string $type): RedirectResponse
    {
        $data = $request->validate(['template' => ['required', 'string', 'max:20']], ['template.required' => 'Choose a design.']);
        $signups->saveDesign(self::require($request, $type), (string) $data['template']);

        return redirect(($type === 'solo' ? '/agents' : '/start').'?step=4#signup');
    }

    /** Step 4: agree and go to the payment page. */
    public function pay(Request $request, Signups $signups, string $type): RedirectResponse
    {
        abort_if(filled($request->input('fax')), 422);
        if ($request->filled('slug')) { // everything in one go (the form before SU2, and partner integrations)
            return $type === 'solo' ? app(SignupController::class)->storeAgent($request, $signups) : app(SignupController::class)->store($request, $signups);
        }
        if ($type === 'brokerage' && ! app(BotProtection::class)->passes($request)) { // TR1
            throw ValidationException::withMessages(['bot' => 'Please complete the check that shows you are not a robot, then try again.']);
        }
        $request->validate(['terms' => ['accepted']], ['terms.accepted' => 'Please agree to the terms to continue.']);

        return redirect()->away($signups->checkout(self::require($request, $type), 'https://'.config('core.hq_host')));
    }

    /** @return array<string, string> the values step 1 starts with */
    private static function prefill(?stdClass $draft, ?stdClass $check, string $type, string $checkToken): array
    {
        if ($draft !== null) {
            return $type === 'solo'
                ? ['name' => (string) $draft->owner_name, 'email' => (string) $draft->owner_email, 'phone' => (string) ($draft->phone ?? ''), 'state' => (string) ($draft->state ?? ''), 'slug' => (string) $draft->slug,
                    'business' => $draft->brokerage !== $draft->owner_name ? (string) $draft->brokerage : '']
                : ['brokerage' => (string) $draft->brokerage, 'owner_name' => (string) $draft->owner_name, 'owner_email' => (string) $draft->owner_email, 'phone' => (string) ($draft->phone ?? ''),
                    'state' => (string) ($draft->state ?? ''), 'agents' => (string) ($draft->agents ?? ''), 'slug' => (string) $draft->slug];
        }
        if ($check !== null) {
            return ['brokerage' => (string) $check->brokerage, 'owner_name' => (string) $check->contact_name, 'owner_email' => (string) $check->email,
                'state' => (string) ($check->state ?? ''), 'agents' => (string) $check->agents, 'slug' => Signups::suggest((string) $check->brokerage), 'check' => $checkToken];
        }

        return [];
    }

    /**
     * What they chose, for the review step.
     *
     * @return array{package: string, price_cents: int, interval: string, design: string, boards: list<array{name: string, fee_cents: int}>, extra_cents: int}
     */
    private static function review(stdClass $draft): array
    {
        $package = Package::query()->whereKey((int) $draft->package_id)->first();
        $ids = array_values(array_filter((array) json_decode((string) ($draft->idx_boards ?? '[]'), true), 'is_string'));
        $boards = $ids === [] ? [] : array_values(collect(SignupController::boardsAndStates()['idxBoards'])->whereIn('ulid', $ids)
            ->map(fn (array $b): array => ['name' => (string) $b['name'], 'fee_cents' => (int) $b['fee_cents']])->sortByDesc('fee_cents')->values()->all());
        $extra = array_sum(array_column(array_slice($boards, 1), 'fee_cents'));

        $price = $package === null ? 0 : ($draft->interval === 'year' ? $package->yearly_cents : $package->monthly_cents);

        return ['package' => $package === null ? '' : $package->name, 'price_cents' => (int) $price,
            'interval' => (string) $draft->interval, 'design' => (string) config('sites.templates.'.($draft->template ?? 'modern').'.name', 'Modern'), 'boards' => $boards, 'extra_cents' => $extra];
    }

    private static function key(string $type): string
    {
        return 'signup.'.($type === 'solo' ? 'solo' : 'brokerage');
    }

    private static function current(Request $request, string $type): ?stdClass
    {
        $ulid = $request->session()->get(self::key($type));
        if (! is_string($ulid)) {
            return null;
        }
        $row = DB::connection(Connections::control())->table('signups')->where('ulid', $ulid)->whereIn('status', ['draft', 'pending'])->first();

        return $row !== null && $row->type === $type ? $row : null;
    }

    private static function require(Request $request, string $type): string
    {
        $draft = self::current($request, $type);
        if ($draft === null) {
            throw ValidationException::withMessages(['signup' => 'Please start with step 1.']);
        }

        return (string) $draft->ulid;
    }
}
