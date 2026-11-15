<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Crm\Booking\Availability;

#[Description('Your open times for a call, showing or listing appointment (from your RightAlly hours, tasks and connected calendar).')]
#[IsReadOnly]
final class OpenTimes extends RightAllyTool
{
    protected string $name = 'open_times';

    public function schema(JsonSchema $schema): array
    {
        return ['kind' => $schema->string()->enum(['call', 'showing', 'listing'])->description('What the time is for (default call)')];
    }

    protected function allows(User $user): bool
    {
        return in_array($user->kind, [UserKind::Agent, UserKind::Owner, UserKind::Staff], true);
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $kind = (string) ($request->validate(['kind' => ['nullable', 'in:call,showing,listing']])['kind'] ?? 'call');
        $booking = app(Availability::class);
        $tz = $booking->timezone();

        return ['timezone' => $tz, 'booking_on' => $booking->for($user->id)['enabled'],
            'times' => array_map(fn (CarbonImmutable $t): string => $t->setTimezone($tz)->format('D M j, g:i A'), $booking->slots($user, $kind, 6, 12))];
    }
}
