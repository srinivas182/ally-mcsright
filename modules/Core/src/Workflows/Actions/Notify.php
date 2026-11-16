<?php

declare(strict_types=1);

namespace Modules\Core\Workflows\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Access\Role;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Workflows\Subject;
use Modules\Core\Workflows\WorkflowAction;
use Modules\Core\Workflows\WorkflowAlertMail;

/** Alert people in the brokerage: the record's agent, the owners, or everyone holding a role. Never the client. */
final class Notify implements WorkflowAction
{
    public function key(): string
    {
        return 'notify';
    }

    public function label(): string
    {
        return 'Send an alert to people in the brokerage';
    }

    public function subjects(): array
    {
        return [];
    }

    public function params(): array
    {
        $roles = Role::query()->orderBy('name')->get()->mapWithKeys(fn (Role $r): array => ['role:'.$r->ulid => 'Everyone with the role '.$r->name])->all();

        return [
            'to' => ['label' => 'Who', 'type' => 'choice', 'options' => ['owner' => 'The agent it belongs to', 'owners' => 'The brokerage owners'] + $roles, 'required' => true],
            'message' => ['label' => 'Message', 'type' => 'text', 'required' => true],
        ];
    }

    public function describe(array $params): string
    {
        $to = ($this->params()['to']['options'] ?? [])[(string) ($params['to'] ?? '')] ?? 'someone';

        return 'Alert '.lcfirst($to).': "'.($params['message'] ?? '').'"';
    }

    public function run(Subject $subject, array $params, array $facts, User $runner): string
    {
        $to = (string) ($params['to'] ?? 'owner');
        $people = match (true) {
            $to === 'owner' => User::query()->whereKey($subject->ownerUserId ?? 0)->get(),
            $to === 'owners' => User::query()->where('kind', UserKind::Owner)->get(),
            str_starts_with($to, 'role:') => User::query()->whereIn('id', DB::connection(Connections::cell())->table('user_roles')
                ->where('role_id', Role::query()->where('ulid', substr($to, 5))->value('id') ?? 0)->pluck('user_id'))->get(),
            default => collect(),
        };
        $people = $people->filter(fn (User $u): bool => $u->isActive());
        foreach ($people as $u) {
            $u->notify(new WorkflowAlertMail(mb_substr((string) ($params['message'] ?? 'Update'), 0, 150), $subject->label, self::path($subject)));
        }

        return 'Alerted '.($people->isEmpty() ? 'nobody (no one matched)' : $people->map(fn (User $u): string => $u->name)->implode(', ')).'.';
    }

    public static function path(Subject $subject): ?string
    {
        $ulid = $subject->model->getAttribute('ulid');

        return is_string($ulid) ? match ($subject->type) {
            'contact' => '/contacts/'.$ulid, 'deal' => '/deals/'.$ulid, 'agent' => '/roster/'.$ulid, default => null,
        } : null;
    }
}
