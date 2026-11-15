<?php

declare(strict_types=1);

namespace Modules\Transactions\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Models\Deal;

#[Description('Your deals in RightAlly (an owner or staff member sees the brokerage\'s): address, status, price and closing date.')]
#[IsReadOnly]
final class MyDeals extends RightAllyTool
{
    protected string $name = 'my_deals';

    public function schema(JsonSchema $schema): array
    {
        return ['status' => $schema->string()->enum(['open', 'closed', 'all'])->description('Which deals (default open)')];
    }

    protected function allows(User $user): bool
    {
        return $user->kind !== UserKind::HqStaff;
    }

    /** @return array<int, array<string, mixed>> */
    protected function run(Request $request, User $user): array
    {
        $status = (string) ($request->validate(['status' => ['nullable', 'in:open,closed,all']])['status'] ?? 'open');

        return DealAccess::query($user)->when($status === 'open', fn ($q) => $q->whereNotIn('status', ['closed', 'cancelled']))
            ->when($status === 'closed', fn ($q) => $q->where('status', 'closed'))->orderBy('closing_date')->limit(100)->get()
            ->map(fn (Deal $d): array => ['id' => $d->ulid, 'address' => trim($d->street.', '.$d->city.' '.$d->state_code), 'type' => $d->type, 'status' => $d->status,
                'price' => '$'.number_format($d->price_cents / 100), 'closing_date' => $d->closing_date?->toDateString(), 'link' => url('/deals/'.$d->ulid)])->values()->all();
    }
}
