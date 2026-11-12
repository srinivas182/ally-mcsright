<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Revenue share (specs/compensation/02, Sprint 11B): dated sponsorships, immutable entries
 * (shares, root fallback, missed earnings, reversals) and the root account setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('rs_sponsorships', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('agent_user_id');
            $table->unsignedBigInteger('sponsor_user_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('reason', 200)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'agent_user_id', 'effective_from'], 'rs_sponsorships_agent_idx');
        });

        $schema->create('rs_entries', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('calculation_id')->constrained('comp_calculations');
            $table->string('deal_ref', 60);
            $table->unsignedBigInteger('source_agent_user_id');
            $table->unsignedBigInteger('recipient_user_id')->nullable(); // null for missed earnings with no upline
            $table->unsignedBigInteger('missed_user_id')->nullable();    // the upline who would have earned
            $table->unsignedTinyInteger('level');
            $table->string('kind', 12); // share, root, missed
            $table->string('reason', 30)->nullable(); // missing_upline, ineligible_upline, fallback_cap
            $table->string('basis', 15);
            $table->bigInteger('base_cents');
            $table->decimal('percent', 7, 4);
            $table->bigInteger('amount_cents');
            $table->unsignedBigInteger('reversal_of')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->date('closing_date');
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'recipient_user_id'], 'rs_entries_recipient_idx');
            $table->index(['tenant_id', 'calculation_id'], 'rs_entries_calc_idx');
        });

        $schema->create('rs_settings', function (Blueprint $table): void {
            $table->tenant();
            $table->unsignedBigInteger('root_user_id')->nullable();
            $table->timestamps();
            $table->primary('tenant_id');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        foreach (['rs_settings', 'rs_entries', 'rs_sponsorships'] as $t) {
            $schema->dropIfExists($t);
        }
    }
};
