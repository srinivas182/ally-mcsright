<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Crm\Connect\GhlCampaigns;
use Modules\Crm\Models\GhlEnrolment;

#[Description('The GoHighLevel workflows and campaigns this person can add RightAlly contacts to, and what is already set up. If GoHighLevel is not connected, says so (offer to connect it).')]
#[IsReadOnly]
final class GhlCampaignList extends RightAllyTool
{
    protected string $name = 'gohighlevel_campaigns';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function allows(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true);
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        $ghl = app(GhlCampaigns::class);
        $c = $ghl->connectionFor($user);
        if ($c === null) {
            return ['connected' => false, 'note' => 'GoHighLevel is not connected. Offer to connect it (app key "gohighlevel").'];
        }
        $t = $ghl->targets($c);

        return ['connected' => true, 'workflows' => $t['workflows'], 'campaigns' => $t['campaigns'],
            'set_up' => GhlEnrolment::query()->where('connection_id', $c->id)->get()->map(fn (GhlEnrolment $e): array => ['name' => $e->name, 'into' => $e->target_name,
                'active' => $e->active, 'added' => $e->enrolled])->values()->all()];
    }
}
