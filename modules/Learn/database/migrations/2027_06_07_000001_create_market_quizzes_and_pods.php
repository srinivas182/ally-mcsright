<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** LN4 (specs/team/…, E20): local market quizzes, and accountability pods with weekly check-ins. */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('learn_market_quizzes', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('place', 80);
            $table->date('on');
            $table->unsignedTinyInteger('score');
            $table->unsignedTinyInteger('total');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'place', 'on'], 'learn_market_quizzes_day_idx');
        });
        $cell->create('learn_pods', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 80);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        $cell->create('learn_pod_members', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('pod_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->unique(['pod_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
        $cell->create('learn_pod_checkins', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('pod_id');
            $table->unsignedBigInteger('user_id');
            $table->date('week'); // the Monday
            $table->string('goal', 300);
            $table->string('done', 300)->nullable();
            $table->string('help', 300)->nullable();
            $table->timestamps();
            $table->unique(['pod_id', 'user_id', 'week']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        foreach (['learn_pod_checkins', 'learn_pod_members', 'learn_pods', 'learn_market_quizzes'] as $t) {
            $cell->dropIfExists($t);
        }
    }
};
