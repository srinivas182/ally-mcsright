<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Yearly goals: the brokerage's (user_id null) and each agent's (specs/insights/01, K1). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('insights_goals', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('gci_cents')->nullable();
            $table->unsignedInteger('sides')->nullable();
            $table->unsignedBigInteger('volume_cents')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'year'], 'insights_goals_unq');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('insights_goals');
    }
};
