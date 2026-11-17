<?php

declare(strict_types=1);

namespace Modules\Learn;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Learning\TrainingGate;
use Modules\Learn\Models\Course;
use Modules\Learn\Models\Enrolment;
use Modules\Learn\Models\Gate;

/**
 * Certifications that open tools (LN2, specs/learn/02). The broker can require a course before someone uses a tool
 * that can hurt the brokerage if misused: automatic follow-up plans (texts to many people) or the Prospector
 * (cold calls, where Do Not Call rules apply). Until they finish the course, the tool explains what to do in one
 * sentence with the course name. Owners are never blocked. With no requirement set, every tool stays open.
 */
final class Certifications implements TrainingGate
{
    public const TOOLS = [
        'follow_up_plans' => 'Automatic follow-up plans (texts and emails to many people)',
        'prospector' => 'Prospector (calling homeowners who are not yet clients)',
    ];

    public function missing(User $user, string $tool): ?string
    {
        if ($user->kind === UserKind::Owner || ! isset(self::TOOLS[$tool])) {
            return null;
        }
        $gate = Gate::query()->where('tool', $tool)->first();
        $course = $gate !== null ? Course::query()->whereKey($gate->course_id)->where('status', 'published')->first() : null;
        if ($course === null || Enrolment::query()->where('course_id', $course->id)->where('user_id', $user->id)->whereNotNull('completed_at')->exists()) {
            return null;
        }

        return "Your brokerage asks you to finish the course \"{$course->title}\" in Learn first. It takes about {$course->minutes} minutes.";
    }

    /** @return array<string, array{label: string, course: string|null}> tool => required course ulid */
    public function settings(): array
    {
        $gates = Gate::query()->get()->keyBy('tool');
        $out = [];
        foreach (self::TOOLS as $tool => $label) {
            $id = $gates->get($tool)?->course_id;
            $out[$tool] = ['label' => $label, 'course' => $id !== null ? Course::query()->whereKey($id)->value('ulid') : null];
        }

        return $out;
    }

    public function set(string $tool, ?Course $course, User $by): void
    {
        if (! Library::manages($by)) {
            throw ValidationException::withMessages(['tool' => 'Setting required training needs the "Manage training" permission.']);
        }
        if (! isset(self::TOOLS[$tool])) {
            throw ValidationException::withMessages(['tool' => 'Unknown tool.']);
        }
        if ($course === null) {
            Gate::query()->where('tool', $tool)->delete();
        } else {
            if ($course->status !== 'published') {
                throw ValidationException::withMessages(['course' => 'Publish the course first.']);
            }
            Gate::query()->updateOrCreate(['tool' => $tool], ['course_id' => $course->id, 'set_by' => $by->id]);
        }
        app(AuditLogger::class)->record('learn.gate.set', metadata: ['tool' => $tool, 'course' => $course?->ulid], severity: 'notice');
    }
}
