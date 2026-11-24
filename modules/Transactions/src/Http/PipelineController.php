<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Models\Deal;

/**
 * PG1: the CRM pipeline. Deals that are not under contract yet (pre-contract) move through stages: new, showing or
 * meeting, offer or listing agreement. Once a contract is signed the deal moves to Transactions.
 */
final class PipelineController
{
    /** @var array<string, string> */
    public const STAGES = ['new' => 'New', 'showing' => 'Showing homes or meeting', 'offer' => 'Offer out or listing signed'];

    public function index(Request $request): Response
    {
        $user = self::user($request);
        $deals = DealAccess::query($user)->where('status', 'pre_contract')->orderByDesc('updated_at')->limit(500)->get();
        $agents = User::query()->whereIn('id', $deals->pluck('agent_user_id')->unique()->all())->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->name]);
        $clients = DB::connection(Connections::cell())->table('tx_parties')->whereIn('deal_id', $deals->pluck('id')->all())->whereIn('role', ['buyer', 'seller', 'tenant', 'landlord'])
            ->orderBy('id')->get(['deal_id', 'name'])->groupBy('deal_id')->map(fn ($rows): string => (string) ($rows->first()->name ?? ''));
        $now = CarbonImmutable::now();

        return Inertia::render('crm/pipeline', [
            'stages' => array_map(fn (string $k, string $v): array => ['key' => $k, 'label' => $v], array_keys(self::STAGES), self::STAGES),
            'deals' => $deals->map(fn (Deal $d): array => [
                'ulid' => $d->ulid, 'street' => $d->street, 'city' => $d->city, 'type' => $d->type, 'price_cents' => $d->price_cents,
                'stage' => isset(self::STAGES[(string) $d->getAttribute('pipeline_stage')]) ? (string) $d->getAttribute('pipeline_stage') : 'new',
                'client' => $clients[$d->id] ?? null, 'agent' => $agents[$d->agent_user_id] ?? null,
                'days' => (int) CarbonImmutable::parse((string) ($d->getAttribute('pipeline_stage_at') ?? $d->getAttribute('created_at')))->diffInDays($now),
            ])->values(),
            'canCreateFor' => DealAccess::reviewer($user) ? User::query()->where('kind', UserKind::Agent)->where('status', 'active')->orderBy('first_name')->get()
                ->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name]) : [],
        ]);
    }

    public function stage(Request $request, Deal $deal): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(DealAccess::canSee($user, $deal), 403);
        abort_unless($user->kind !== UserKind::Assistant || $user->assisted_user_id === $deal->agent_user_id, 403);
        $data = $request->validate(['stage' => ['required', Rule::in(array_keys(self::STAGES))]]);
        abort_unless($deal->status === 'pre_contract', 422, 'Only deals not under contract yet are in the pipeline.');
        $deal->forceFill(['pipeline_stage' => $data['stage'], 'pipeline_stage_at' => now()])->save();

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
