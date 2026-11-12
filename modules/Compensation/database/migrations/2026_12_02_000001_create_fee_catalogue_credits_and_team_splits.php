<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Versioned fee catalogue (legacy rule 14), yearly fee totals, credits, and team splits
 * (specs/compensation/01, Sprint 9B).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('comp_fees', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('code', 40);
            $table->string('name', 80);
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'comp_fees_code_unq');
        });

        $schema->create('comp_fee_versions', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('fee_id')->constrained('comp_fees')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->string('stage', 5); // pre, post
            $table->string('calc', 7); // flat, percent
            $table->string('amount', 20);
            $table->string('charged_to', 10)->default('agent');
            $table->string('payable_to', 12)->default('brokerage');
            $table->boolean('counts_toward_cap')->default(false);
            $table->boolean('revenue_share_eligible')->default(true);
            $table->unsignedBigInteger('annual_cap_cents')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['fee_id', 'version'], 'comp_fee_versions_unq');
        });

        $schema->create('comp_fee_entries', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('agent_user_id');
            $table->unsignedSmallInteger('year');
            $table->string('code', 40);
            $table->foreignId('calculation_id')->constrained('comp_calculations')->cascadeOnDelete();
            $table->bigInteger('cents');
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'agent_user_id', 'year', 'code'], 'comp_fee_entries_idx');
        });

        $schema->create('comp_credits', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('agent_user_id');
            $table->string('kind', 12); // signing, production, marketing, other
            $table->string('name', 80);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('remaining_cents');
            $table->date('expires_on')->nullable();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'agent_user_id'], 'comp_credits_agent_idx');
        });

        $schema->create('comp_credit_uses', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('credit_id')->constrained('comp_credits')->cascadeOnDelete();
            $table->foreignId('calculation_id')->constrained('comp_calculations')->cascadeOnDelete();
            $table->bigInteger('cents');
            $table->dateTime('created_at');
        });

        $schema->table('teams', function (Blueprint $table): void {
            $table->decimal('comp_split_percent', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('teams', fn (Blueprint $table) => $table->dropColumn('comp_split_percent'));
        foreach (['comp_credit_uses', 'comp_credits', 'comp_fee_entries', 'comp_fee_versions', 'comp_fees'] as $t) {
            $schema->dropIfExists($t);
        }
    }
};
