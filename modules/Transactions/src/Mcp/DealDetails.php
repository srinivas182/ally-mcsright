<?php

declare(strict_types=1);

namespace Modules\Transactions\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Deals;
use Modules\Transactions\KeyDates;
use Modules\Transactions\Models\Deal;

#[Description('One deal: its key dates (deadlines, overdue ones flagged) and the paperwork still missing. Never shows commission splits or bank details.')]
#[IsReadOnly]
final class DealDetails extends RightAllyTool
{
    protected string $name = 'deal_details';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->description('The deal id from my_deals')->required()];
    }

    protected function allows(User $user): bool
    {
        return $user->kind !== UserKind::HqStaff;
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $id = (string) $request->validate(['id' => ['required', 'string', 'size:26']])['id'];
        $deal = Deal::query()->where('ulid', $id)->first();
        if ($deal === null || ! DealAccess::canSee($user, $deal)) {
            throw ValidationException::withMessages(['id' => 'No deal with that id that you can see.']);
        }

        return ['id' => $deal->ulid, 'address' => trim($deal->street.', '.$deal->city.' '.$deal->state_code), 'status' => $deal->status,
            'contract_date' => $deal->contract_date?->toDateString(), 'closing_date' => $deal->closing_date?->toDateString(),
            'key_dates' => array_map(fn (array $d): array => ['label' => $d['label'], 'due_on' => $d['due_on'], 'done' => $d['done'], 'overdue' => $d['overdue']],
                app(KeyDates::class)->forDeal($deal)),
            'missing_paperwork' => app(Deals::class)->missing($deal), 'link' => url('/deals/'.$deal->ulid)];
    }
}
