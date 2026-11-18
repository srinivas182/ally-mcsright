<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Learning\TrainingGate;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Learn\CourseBuilder;
use Modules\Learn\LaunchProgram;
use Modules\Learn\Library;
use Modules\Learn\Models\Course;
use Modules\Learn\Models\Launch;
use Modules\Learn\Models\Lesson;
use Modules\Learn\Scorecard;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->withoutTwoFactor()->create(['first_name' => 'Ann', 'last_name' => 'New']);
    $this->mo = User::factory()->create(['first_name' => 'Mo', 'last_name' => 'Mentor']);
    $this->zed = User::factory()->create(['first_name' => 'Zed', 'last_name' => 'Mentor']);
});

it('runs the 30-day launch: steps tick themselves or by hand, the check-in only by the broker or mentor', function (): void {
    app(Library::class)->addStarter('rightally-basics', $this->owner);
    $this->actingAs($this->mo)->post('/learn/mentor', ['active' => true, 'strengths' => 'Listings', 'max_mentees' => 1]);
    $this->actingAs($this->zed)->post('/learn/mentor', ['active' => true, 'strengths' => 'Buyers', 'max_mentees' => 2]);
    expect(array_column(LaunchProgram::mentorSuggestions(), 'name'))->toBe(['Mo Mentor', 'Zed Mentor']);

    $this->actingAs($this->ann)->post('/learn/launch', ['agent' => $this->ann->id])->assertSessionHasErrors('agent');
    $this->actingAs($this->owner)->post('/learn/launch', ['agent' => $this->ann->id, 'mentor' => $this->mo->id])->assertSessionHasNoErrors();
    expect(array_column(LaunchProgram::mentorSuggestions(), 'name'))->toBe(['Zed Mentor']); // Mo is full

    $launch = Launch::query()->sole();
    $this->actingAs($this->ann)->get('/learn/launch')->assertInertia(fn (Assert $p) => $p->component('learn/launch')
        ->where('mine.mentor', 'Mo Mentor')->where('mine.steps.0.key', 'two_factor')->where('mine.steps.0.can_tick', false)
        ->where('mine.steps.1.can_tick', true)->where('mine.steps.10.key', 'review')->where('mine.steps.10.can_tick', false));
    $this->actingAs($this->ann)->get('/learn')->assertInertia(fn (Assert $p) => $p->where('courses.0.assigned', true)->where('courses.0.due_on', '2026-06-02'));

    $this->actingAs($this->ann)->post("/learn/launch/{$launch->id}/steps/profile")->assertRedirect();
    $this->actingAs($this->ann)->post("/learn/launch/{$launch->id}/steps/review")->assertForbidden();
    $this->actingAs($this->ann)->post("/learn/launch/{$launch->id}/steps/two_factor")->assertForbidden();
    $this->actingAs($this->mo)->get('/learn/launch')->assertInertia(fn (Assert $p) => $p->where('mentoring.0.agent', 'Ann New'));
    $this->actingAs($this->mo)->post("/learn/launch/{$launch->id}/steps/review")->assertRedirect();
    expect($launch->fresh()->done)->toBe(['profile', 'review']);

    $this->travel(5)->days();
    $this->actingAs($this->owner)->get('/learn/launch')->assertInertia(fn (Assert $p) => $p->where('launches.0.steps.0.late', true)
        ->where('launches.0.steps.1.late', false));
});

it('asks for a course before a risky tool, and says which one', function (): void {
    $course = app(Library::class)->addStarter('texting-and-fair-housing', $this->owner);
    $gate = app(TrainingGate::class);
    expect($gate->missing($this->ann, 'follow_up_plans'))->toBeNull();

    $this->actingAs($this->ann)->post('/learn/manage/gates', ['tool' => 'follow_up_plans', 'course' => $course->ulid])->assertSessionHasErrors('tool');
    $this->actingAs($this->owner)->post('/learn/manage/gates', ['tool' => 'follow_up_plans', 'course' => $course->ulid])->assertSessionHasNoErrors();
    expect($gate->missing($this->ann, 'follow_up_plans'))->toContain('Texting rules and fair housing basics')
        ->and($gate->missing($this->owner, 'follow_up_plans'))->toBeNull()
        ->and($gate->missing($this->ann, 'prospector'))->toBeNull();

    app(FollowUpPlans::class)->seedTemplates();
    $contact = app(ManageContacts::class)->create(['first_name' => 'Sam', 'email' => 'sam@x.test'], $this->ann);
    expect(fn () => app(FollowUpPlans::class)->enroll($contact, FollowUpPlan::query()->where('shared', true)->firstOrFail(), $this->ann))
        ->toThrow(ValidationException::class);

    // Finishing the course opens the tool.
    $lessons = Lesson::query()->where('course_id', $course->id)->orderBy('sort')->get();
    $this->actingAs($this->ann)->post("/learn/courses/{$course->ulid}/start");
    $this->actingAs($this->ann)->post("/learn/courses/{$course->ulid}/lessons/{$lessons[0]->id}/done");
    $this->actingAs($this->ann)->post("/learn/courses/{$course->ulid}/lessons/{$lessons[1]->id}/done");
    $this->actingAs($this->ann)->post("/learn/courses/{$course->ulid}/lessons/{$lessons[2]->id}/quiz", ['answers' => [1, 1, 1]]);
    expect($gate->missing($this->ann, 'follow_up_plans'))->toBeNull();
});

it('drafts a course with AI for the broker to check, never published by itself', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => CourseBuilder::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.course_builder']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    app(CreditLedger::class)->add(null, 50, 'adjust', 'Test credits', $this->owner);
    $ai->respond(json_encode(['title' => 'Great open houses', 'summary' => 'Before, during and after', 'minutes' => 12, 'lessons' => [
        ['title' => 'Before', 'body' => 'Put up signs the day before.'], ['title' => 'After', 'body' => 'Call every visitor within a day.'], ['title' => '', 'body' => 'dropped'],
    ], 'quiz' => [
        ['q' => 'When do you call visitors?', 'options' => ['Within a day', 'Next month'], 'answer' => 0, 'why' => 'Fresh interest.'],
        ['q' => 'Broken', 'options' => ['Only one'], 'answer' => 3],
    ]]));

    $this->actingAs($this->ann)->post('/learn/manage/build', ['topic' => 'Open houses'])->assertSessionHasErrors('topic');
    $this->actingAs($this->owner)->post('/learn/manage/build', ['topic' => 'Open houses', 'notes' => 'Our signs go out Friday.', 'skill' => 'listings'])->assertRedirect();
    $course = Course::query()->sole();
    $lessons = Lesson::query()->where('course_id', $course->id)->orderBy('sort')->get();

    expect($course->status)->toBe('draft')->and($course->source)->toBe('ai')->and($course->skill)->toBe('listings')
        ->and($lessons->pluck('kind')->all())->toBe(['text', 'text', 'quiz'])
        ->and($lessons[0]->body)->toStartWith('> Draft written by AI')
        ->and($lessons[2]->questions)->toHaveCount(1)
        ->and($ai->requests[0]->userMessage)->toContain('Our signs go out Friday.');
    $this->actingAs($this->ann)->get('/learn')->assertInertia(fn (Assert $p) => $p->where('courses', []));
});

it('shows a skill scorecard with the next course to take', function (): void {
    $basics = app(Library::class)->addStarter('rightally-basics', $this->owner);
    app(Library::class)->addStarter('texting-and-fair-housing', $this->owner);
    $quiz = Lesson::query()->where('course_id', $basics->id)->where('kind', 'quiz')->sole();
    $this->actingAs($this->ann)->post("/learn/courses/{$basics->ulid}/start");
    $this->actingAs($this->ann)->post("/learn/courses/{$basics->ulid}/lessons/{$quiz->id}/quiz", ['answers' => [0, 0, 0]]);

    $card = collect(Scorecard::forUser($this->ann->id))->keyBy('skill');
    expect($card['technology']['courses'])->toBe(1)->and($card['technology']['finished'])->toBe(0)->and($card['technology']['score'])->toBe(67)
        ->and($card['compliance']['next']['title'])->toBe('Texting rules and fair housing basics')
        ->and($card['buyers']['courses'])->toBe(0);
});
