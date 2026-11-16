<?php

declare(strict_types=1);

namespace Modules\Movewise\Launch;

use Illuminate\Support\Facades\DB;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Support\Connections as ControlDb;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Package;
use Modules\Movewise\Models\State;
use Modules\Movewise\Movewise;
use Modules\Movewise\Program\Pricing;

/**
 * The launch checks for a state (specs/movewise/08): everything that must be true before people in that state can
 * use Movewise end to end. The legal gate (Movewise::missing) decides whether the switch turns on; these show the
 * operational side, green or red, in HQ and from `movewise:launch-check`.
 */
final class Readiness
{
    public const MIN_AGENTS = 3;

    public function __construct(private readonly Movewise $movewise) {}

    /** @return list<array{check: string, ok: bool, detail: string}> */
    public function for(State $state): array
    {
        $missing = $this->movewise->missing($state);
        $offered = Pricing::offered($state);
        $ids = [];
        foreach (Package::query()->where('state', $state->code)->where('status', 'active')->get() as $p) {
            array_push($ids, ...$p->agentIds());
        }
        $agents = Agent::query()->whereIn('id', array_unique($ids))->where('status', 'active')->count();
        $relays = DB::connection(ControlDb::control())->table('movewise_relay_numbers')->where('active', true)->count();
        $messaging = app(MessagingSettings::class)->credentials() !== null;

        return [
            ['check' => 'Legal gate', 'ok' => $missing === [], 'detail' => $missing === [] ? 'Partner, checklist, MLS feed and launch order are complete' : implode('; ', $missing)],
            ['check' => 'Pricing', 'ok' => $offered !== [], 'detail' => $offered !== [] ? 'Offered: '.implode(', ', array_map(fn (string $m): string => Pricing::MODELS[$m], $offered)) : 'No model is on and priced'],
            ['check' => 'Live agents', 'ok' => $agents >= self::MIN_AGENTS, 'detail' => $agents.' live agents with a live package (at least '.self::MIN_AGENTS.' so people can choose from three)'],
            ['check' => 'Relay numbers', 'ok' => $relays > 0, 'detail' => $relays.' active relay numbers'],
            ['check' => 'Texting', 'ok' => $messaging, 'detail' => $messaging ? 'Twilio is set up' : 'Set up Twilio in HQ → Texts and calls'],
            ['check' => 'Email sender and postal address', 'ok' => trim((string) config('movewise.postal_address')) !== '' && trim((string) config('movewise.mail_from')) !== '',
                'detail' => 'MOVEWISE_MAIL_FROM and MOVEWISE_POSTAL_ADDRESS (required on every alert email)'],
        ];
    }

    public function ready(State $state): bool
    {
        return ! in_array(false, array_column($this->for($state), 'ok'), true);
    }
}
