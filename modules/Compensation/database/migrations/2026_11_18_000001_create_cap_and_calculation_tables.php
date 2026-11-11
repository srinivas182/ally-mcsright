<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Cap periods with entries, and versioned commission calculations (specs/compensation/01, Sprint 4B).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::cell();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create('comp_calculations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('deal_ref', 80);
            $table->string('side', 20);
            $table->unsignedInteger('version');
            $table->string('status', 20); // pending, final, reversed, superseded
            $table->json('inputs');
            $table->json('result');
            $table->json('steps');
            $table->string('change_note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'deal_ref', 'side', 'version'], 'comp_calc_deal_version_unq');
            $table->index(['tenant_id', 'status'], 'comp_calc_status_idx');
        });

        $schema->create('comp_calculation_agents', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('calculation_id')->constrained('comp_calculations')->cascadeOnDelete();
            $table->unsignedBigInteger('agent_user_id');
            $table->string('status', 20);

            $table->index(['tenant_id', 'agent_user_id', 'status'], 'comp_calc_agent_status_idx');
        });

        $schema->create('comp_cap_periods', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('agent_user_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('cap_amount_cents')->nullable();
            $table->bigInteger('company_dollar_cents')->default(0);
            $table->bigInteger('post_cap_fees_cents')->default(0);
            $table->string('status', 20)->default('open'); // open, capped
            $table->timestamps();

            $table->unique(['tenant_id', 'agent_user_id', 'starts_on'], 'comp_cap_period_agent_start_unq');
        });

        $schema->create('comp_cap_entries', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('cap_period_id')->constrained('comp_cap_periods');
            $table->unsignedBigInteger('calculation_id');
            $table->bigInteger('company_dollar_cents');
            $table->bigInteger('post_cap_fee_cents');
            $table->string('reason', 20); // deal, reversal
            $table->dateTime('created_at');

            $table->index(['tenant_id', 'cap_period_id'], 'comp_cap_entries_period_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('comp_cap_entries');
        $schema->dropIfExists('comp_cap_periods');
        $schema->dropIfExists('comp_calculation_agents');
        $schema->dropIfExists('comp_calculations');
    }
};
