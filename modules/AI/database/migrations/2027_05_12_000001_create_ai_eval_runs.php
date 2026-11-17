<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** AQ1 (specs/ai/08): results of running the AI test sets against real models (control plane, HQ only). */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('ai_eval_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('status', 16); // running, passed, failures, budget_stopped
            $table->unsignedInteger('cases')->default(0);
            $table->unsignedInteger('passed')->default(0);
            $table->unsignedBigInteger('cost_microcents')->default(0);
            $table->unsignedBigInteger('budget_microcents');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::connection($this->getConnection())->create('ai_eval_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('prompt_key', 120);
            $table->string('prompt_version', 20);
            $table->string('case_name', 120);
            $table->string('model_id', 120)->nullable();
            $table->boolean('passed');
            $table->json('failures');
            $table->string('answer_excerpt', 500)->nullable();
            $table->unsignedBigInteger('cost_microcents')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->dateTime('created_at');
            $table->index(['prompt_key', 'run_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('ai_eval_results');
        Schema::connection($this->getConnection())->dropIfExists('ai_eval_runs');
    }
};
