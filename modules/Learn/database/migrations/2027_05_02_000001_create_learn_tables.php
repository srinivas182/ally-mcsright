<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** LN1 (specs/learn/01): courses with lessons and quizzes, enrolments, and the brokerage's knowledge base. */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection(Connections::cell());
        $s->create('learn_courses', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->ulid('ulid')->unique();
            $table->string('title', 160);
            $table->string('summary', 500)->nullable();
            $table->string('source', 16)->default('brokerage'); // brokerage, rightally, ai
            $table->string('starter_key', 60)->nullable();
            $table->string('status', 12)->default('draft'); // draft, published, archived
            $table->unsignedSmallInteger('pass_mark')->default(80);
            $table->unsignedSmallInteger('minutes')->default(10);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
        $s->create('learn_lessons', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('course_id');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('title', 160);
            $table->string('kind', 10); // text, video, quiz
            $table->text('body')->nullable();
            $table->string('video_url', 300)->nullable();
            $table->json('questions')->nullable();
            $table->timestamps();
            $table->index(['course_id', 'sort']);
        });
        $s->create('learn_enrolments', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->date('due_on')->nullable();
            $table->json('done')->nullable(); // lesson ids finished
            $table->json('scores')->nullable(); // lesson id => best quiz score (percent)
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('reminded_at')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
        $s->create('learn_articles', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->ulid('ulid')->unique();
            $table->string('title', 160);
            $table->string('category', 60);
            $table->text('body');
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 12)->default('published'); // draft, published, archived
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'category']);
        });
        $s->create('learn_article_reads', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedSmallInteger('version');
            $table->dateTime('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['article_id', 'user_id']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection(Connections::cell());
        foreach (['learn_article_reads', 'learn_articles', 'learn_enrolments', 'learn_lessons', 'learn_courses'] as $t) {
            $s->dropIfExists($t);
        }
    }
};
