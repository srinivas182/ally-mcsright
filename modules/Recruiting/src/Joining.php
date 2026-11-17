<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Actions\ManageInvitations;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Events\UserActivated;
use Modules\Recruiting\Models\Recruit;
use Modules\Recruiting\Models\RecruitNote;

/**
 * From recruit to agent (RC3, specs/grow/17): the recruiter invites them into RightAlly from their recruiting page
 * (with their sponsor, so revenue share is right from day one), and when they accept, the recruit is marked joined
 * and linked to their account so results can be followed.
 */
final class Joining
{
    public function invite(Recruit $recruit, User $by): void
    {
        abort_unless($by->can('core.users.invite'), 403);
        if ($recruit->email === null) {
            throw ValidationException::withMessages(['invite' => 'Add their email first.']);
        }
        if ($recruit->user_id !== null) {
            throw ValidationException::withMessages(['invite' => 'They have already joined.']);
        }
        $parts = explode(' ', trim($recruit->name), 2);
        $invitation = app(ManageInvitations::class)->invite($recruit->email, $parts[0], $parts[1] ?? '', UserKind::Agent, $by, null, $recruit->sponsor_user_id);
        $recruit->fill(['invitation_id' => $invitation->id, 'invited_at' => now()])->save();
        if ($recruit->stage !== 'joined') {
            app(Pipeline::class)->move($recruit, 'offer', $by);
        }
        RecruitNote::query()->create(['recruit_id' => $recruit->id, 'user_id' => $by->id, 'kind' => 'stage', 'body' => "Invited to RightAlly at {$recruit->email}.", 'occurred_at' => now()]);
    }

    /** They accepted: joined, linked to their account. */
    public function activated(UserActivated $event): void
    {
        $user = User::query()->find($event->userId);
        if ($user === null) {
            return;
        }
        $recruit = Recruit::query()->where('email', mb_strtolower((string) $user->email))->orderByDesc('id')->first();
        if ($recruit === null || $recruit->user_id !== null) {
            return;
        }
        $from = Recruit::STAGES[$recruit->stage];
        $recruit->fill(['user_id' => $user->id, 'joined_on' => CarbonImmutable::today(), 'stage' => 'joined', 'stage_changed_on' => CarbonImmutable::today(),
            'next_step' => null, 'next_step_on' => null])->save();
        RecruitNote::query()->create(['recruit_id' => $recruit->id, 'kind' => 'stage', 'body' => "{$from} → Joined: accepted the invitation.", 'occurred_at' => now()]);
    }
}
