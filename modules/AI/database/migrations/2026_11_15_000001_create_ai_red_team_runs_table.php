<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Results of the weekly red-team run against real models (control plane, HQ only).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('ai_red_team_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('status', 20); // running, passed, warning, failed, budget_stopped
            $table->unsignedInteger('scenarios_run')->default(0);
            $table->unsignedInteger('injections_followed')->default(0);
            $table->unsignedInteger('critical_followed')->default(0);
            $table->unsignedInteger('blocked_by_policy')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedBigInteger('cost_microcents')->default(0);
            $table->unsignedBigInteger('budget_microcents');
            $table->json('results');
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('ai_red_team_runs');
    }
};
