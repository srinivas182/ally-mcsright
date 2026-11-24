<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * CM1: ranks (an agent's level, earned by production and recruits, which can open revenue-share levels and point
 * to a better commission plan), and onboarding plans retired (archived; fees live in the fee catalogue).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::cell();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('comp_ranks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 60);
            $table->unsignedTinyInteger('position');            // 1 is the first rank
            $table->unsignedSmallInteger('min_closings')->default(0);
            $table->unsignedBigInteger('min_gci_cents')->default(0);
            $table->unsignedSmallInteger('min_recruits')->default(0); // active direct recruits who closed a deal in the window
            $table->unsignedTinyInteger('window_months')->default(12);
            $table->unsignedTinyInteger('unlock_levels')->nullable(); // revenue-share levels this rank opens
            $table->unsignedBigInteger('plan_id')->nullable();        // the commission plan this rank moves up to
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'position'], 'comp_ranks_tenant_position_unique');
        });

        DB::connection($this->getConnection())->table('comp_plans')->where('type', 'onboarding')->where('status', 'active')
            ->update(['status' => 'archived', 'is_default' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('comp_ranks');
        DB::connection($this->getConnection())->table('comp_plans')->where('type', 'onboarding')->where('status', 'archived')
            ->update(['status' => 'active', 'updated_at' => now()]);
    }
};
