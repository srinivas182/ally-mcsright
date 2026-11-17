<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Learn\Library;
use Modules\Learn\Models\Article;
use Modules\Learn\Models\Course;
use Modules\Learn\Models\Enrolment;
use Modules\Learn\Models\Lesson;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Agent']);
});

it('adds a RightAlly starter course, assigns it with a due date, and agents see it first', function (): void {
    $this->actingAs($this->ann)->post('/learn/manage/starters/rightally-basics')->assertForbidden();
    $this->actingAs($this->owner)->post('/learn/manage/starters/rightally-basics')->assertRedirect();
    $course = Course::query()->sole();
    expect($course->status)->toBe('published')->and($course->source)->toBe('rightally')->and(Lesson::query()->where('course_id', $course->id)->count())->toBe(5);
    $this->actingAs($this->owner)->post('/learn/manage/starters/rightally-basics'); // added once only
    expect(Course::query()->count())->toBe(1);

    $this->actingAs($this->owner)->post("/learn/manage/courses/{$course->ulid}/assign", ['people' => [$this->ann->id], 'due_on' => '2026-06-15'])->assertSessionHasNoErrors();
    $this->actingAs($this->ann)->get('/learn')->assertInertia(fn (Assert $p) => $p->component('learn/index')
        ->where('courses.0.assigned', true)->where('courses.0.due_on', '2026-06-15')->where('courses.0.percent', 0));
    $this->actingAs($this->bob)->get('/learn')->assertInertia(fn (Assert $p) => $p->where('courses.0.enrolled', false));
});

it('takes a course: lessons marked done, a quiz passed with reasons shown, then a certificate', function (): void {
    $course = app(Library::class)->addStarter('texting-and-fair-housing', $this->owner);
    $lessons = Lesson::query()->where('course_id', $course->id)->orderBy('sort')->get();
    $this->actingAs($this->ann)->get("/learn/courses/{$course->ulid}/certificate.pdf")->assertNotFound();
    $this->actingAs($this->ann)->post("/learn/courses/{$course->ulid}/start");
    $this->actingAs($this->ann)->post("/learn/courses/{$course->ulid}/lessons/{$lessons[0]->id}/done");
    $this->actingAs($this->ann)->post("/learn/courses/{$course->ulid}/lessons/{$lessons[2]->id}/done")->assertSessionHasErrors('lesson'); // a quiz is passed, not ticked

    $this->actingAs($this->ann)->post("/learn/courses/{$course->ulid}/lessons/{$lessons[2]->id}/quiz", ['answers' => [0, 1, 0]])
        ->assertSessionHas('quiz', fn (array $q): bool => $q['score'] === 33 && $q['passed'] === false && $q['results'][0]['correct'] === false
            && $q['results'][0]['why'] !== '');
    $this->actingAs($this->ann)->post("/learn/courses/{$course->ulid}/lessons/{$lessons[2]->id}/quiz", ['answers' => [1, 1, 1]])
        ->assertSessionHas('quiz', fn (array $q): bool => $q['score'] === 100 && $q['passed']);
    expect(Enrolment::query()->sole()->completed_at)->toBeNull(); // lesson 2 still unread
    $this->actingAs($this->ann)->post("/learn/courses/{$course->ulid}/lessons/{$lessons[1]->id}/done");
    expect(Enrolment::query()->sole()->completed_at)->not->toBeNull();

    $pdf = $this->actingAs($this->ann)->get("/learn/courses/{$course->ulid}/certificate.pdf")->assertOk();
    expect($pdf->headers->get('content-type'))->toBe('application/pdf');
    $csv = $this->actingAs($this->owner)->get('/learn/manage/report.csv')->streamedContent();
    expect($csv)->toContain('Ann Agent')->toContain('Texting rules and fair housing basics')->toContain('2026-06-01');
});

it('checks lessons when a course is written: allowed video sites, quizzes with a right answer', function (): void {
    $payload = ['title' => 'Lockbox and showings', 'summary' => 'How we show homes', 'lessons' => [
        ['title' => 'Video', 'kind' => 'video', 'video_url' => 'https://evil.example.com/v.mp4'],
    ]];
    $this->actingAs($this->owner)->post('/learn/manage/courses', $payload)->assertSessionHasErrors('lessons');
    $payload['lessons'] = [
        ['title' => 'Watch', 'kind' => 'video', 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'body' => 'Notes'],
        ['title' => 'Quiz', 'kind' => 'quiz', 'questions' => [['q' => 'Who opens the lockbox?', 'options' => ['The showing agent', 'The buyer'], 'answer' => 0, 'why' => 'Only agents.']]],
    ];
    $this->actingAs($this->owner)->post('/learn/manage/courses', $payload + ['publish' => true])->assertRedirect();
    $course = Course::query()->sole();
    expect($course->status)->toBe('published');
    $this->actingAs($this->ann)->get("/learn/courses/{$course->ulid}")->assertInertia(fn (Assert $p) => $p->component('learn/course')
        ->where('lessons.0.embed', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->where('lessons.1.questions.0.options', ['The showing agent', 'The buyer'])
        ->missing('lessons.1.questions.0.answer')); // the right answer is never sent before they answer

    expect(Library::embed('https://vimeo.com/123456789'))->toBe('https://player.vimeo.com/video/123456789')
        ->and(Library::embed('https://www.loom.com/share/0123456789abcdef0123456789abcdef'))->toBe('https://www.loom.com/embed/0123456789abcdef0123456789abcdef')
        ->and(Library::embed('http://youtube.com/watch?v=dQw4w9WgXcQ'))->toBeNull();
});

it('keeps an office knowledge base where required pages are confirmed again after each change', function (): void {
    $this->actingAs($this->ann)->post('/learn/manage/kb', ['title' => 'x', 'body' => 'y'])->assertForbidden();
    $this->actingAs($this->owner)->post('/learn/manage/kb', ['title' => 'Lockbox rules', 'category' => 'Office', 'body' => 'Never share the **lockbox** code.', 'required' => true]);
    $page = Article::query()->sole();

    $this->actingAs($this->ann)->get('/learn')->assertInertia(fn (Assert $p) => $p->where('outstanding', 1)->where('knowledge.Office.0.needs_ack', true));
    $this->actingAs($this->ann)->get("/learn/kb/{$page->ulid}")->assertInertia(fn (Assert $p) => $p->where('needs_ack', true)
        ->where('article.html', fn (string $h): bool => str_contains($h, '<strong>lockbox</strong>')));
    $this->actingAs($this->ann)->post("/learn/kb/{$page->ulid}/acknowledge");
    $this->actingAs($this->ann)->get('/learn')->assertInertia(fn (Assert $p) => $p->where('outstanding', 0));

    $this->actingAs($this->owner)->post("/learn/manage/kb/{$page->ulid}", ['title' => 'Lockbox rules', 'category' => 'Office', 'body' => 'Never share the code. Ever.', 'required' => true]);
    expect($page->fresh()->version)->toBe(2);
    $this->actingAs($this->ann)->get('/learn?q=lockbox')->assertInertia(fn (Assert $p) => $p->where('outstanding', 1)->where('results.0.title', 'Lockbox rules'));
});
