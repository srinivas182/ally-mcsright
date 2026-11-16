<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** The business planner's inputs, kept with the agent's yearly goal (specs/insights/01, K3). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('insights_goals', function (Blueprint $table): void {
            $table->json('plan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('insights_goals', function (Blueprint $table): void {
            $table->dropColumn('plan');
        });
    }
};
