<?php

declare(strict_types=1);

namespace Modules\Crm\Mcp;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\RightAllyTool;
use Modules\Crm\Connect\GhlCampaigns;
use Modules\Crm\SmartLists;

#[Description('Put RightAlly contacts (a tag, or a smart list by name) into a GoHighLevel workflow or campaign the person built, and keep adding new matches hourly. Consent is checked for each person. Shows how many will go and how many are left out first. Use gohighlevel_campaigns to find the workflow or campaign id.')]
final class GhlAddToCampaign extends RightAllyTool
{
    protected string $name = 'gohighlevel_add_to_campaign';

    protected bool $writes = true;

    protected bool $confirmFirst = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'target_type' => $schema->string()->enum(['workflow', 'campaign', 'tag'])->description('workflow, campaign, or tag (a trigger tag)')->required(),
            'target_id' => $schema->string()->description('The workflow or campaign id, or the trigger tag\'s name')->required(),
            'tag' => $schema->string()->description('Who: contacts with this RightAlly tag'),
            'smart_list' => $schema->string()->description('Who: a smart list, by name'),
            'channel' => $schema->string()->enum(['email', 'sms', 'any'])->description('What the campaign sends: email (default), sms, or any'),
        ];
    }

    protected function allows(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true);
    }

    /** @return array<mixed> */
    protected function preview(Request $request, User $user): array
    {
        [$c, $in] = $this->input($request, $user);

        return ['action' => 'Add to GoHighLevel '.$in['target_type'], 'into' => $in['target_id'], 'who' => ($in['source_type'] === 'tag' ? 'Tag ' : 'Smart list ').$this->label($in, $user)]
            + app(GhlCampaigns::class)->preview($c, $user, $in) + ['then' => 'New matches go in every hour.'];
    }

    /** @return array<string, mixed> */
    protected function run(Request $request, User $user): array
    {
        [$c, $in] = $this->input($request, $user);
        $ghl = app(GhlCampaigns::class);
        $e = $ghl->create($c, $user, $in);

        return ['set_up' => $e->name, 'into' => $e->target_name, 'added_now' => $ghl->run($c, $e), 'page' => '/settings/ghl-campaigns'];
    }

    /** @return array{0: ConnectionData, 1: array<string, mixed>} */
    private function input(Request $request, User $user): array
    {
        $c = app(GhlCampaigns::class)->connectionFor($user) ?? throw ValidationException::withMessages(['app' => 'GoHighLevel isn\'t connected. Connect it under Apps first.']);
        $tag = trim((string) $request->get('tag', ''));
        $listName = mb_strtolower(trim((string) $request->get('smart_list', '')));
        $source = ['source_type' => 'tag', 'source_value' => $tag];
        if ($tag === '') {
            $list = collect(app(SmartLists::class)->listsFor($user))->first(fn (array $l): bool => mb_strtolower($l['name']) === $listName || $l['key'] === $listName);
            if ($list === null) {
                throw ValidationException::withMessages(['smart_list' => 'Name a tag or one of your smart lists.']);
            }
            $source = ['source_type' => 'smart_list', 'source_value' => (string) $list['key']];
        }

        return [$c, $source + ['target_type' => (string) $request->get('target_type', ''), 'target_id' => (string) $request->get('target_id', ''),
            'channel' => (string) $request->get('channel', 'email')]];
    }

    /** @param  array<string, mixed>  $in */
    private function label(array $in, User $user): string
    {
        if ($in['source_type'] === 'tag') {
            return (string) $in['source_value'];
        }

        return (string) (collect(app(SmartLists::class)->listsFor($user))->firstWhere('key', $in['source_value'])['name'] ?? 'smart list');
    }
}
