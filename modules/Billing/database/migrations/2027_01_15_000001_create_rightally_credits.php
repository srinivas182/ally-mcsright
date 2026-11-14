<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint 18A: RightAlly Credits (specs/core/07, specs/billing/01): HQ's credit value and price list, credits included
 * in packages, and each brokerage's credit accounts and append-only ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());
        $cell = Schema::connection(Connections::cell());

        $control->create('credit_settings', function (Blueprint $table): void {
            $table->id();
            // What one credit is worth to a customer, in millionths of a cent (1,000,000 = 1 cent).
            $table->unsignedBigInteger('credit_value_microcents')->default(1_000_000);
            // Off: usage is recorded and shown but nothing is blocked (rollout, while packages get their credits).
            // On: an AI action without enough credits is refused with a friendly message.
            $table->boolean('enforce')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $control->create('credit_rate_card', function (Blueprint $table): void {
            $table->id();
            $table->string('action_key', 80)->unique();
            $table->string('label', 120);
            $table->string('category', 20)->default('ai'); // ai, messaging, other
            $table->unsignedInteger('credits');
            // Automations (assistant, auto-responder, summaries) always use the brokerage pool and are reported apart.
            $table->boolean('automation')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $control->table('billing_packages', function (Blueprint $table): void {
            $table->unsignedInteger('included_credits')->default(0)->after('ailly_conversations');
        });

        $cell->create('credit_accounts', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id')->nullable(); // null: the brokerage pool
            $table->integer('balance')->default(0);
            $table->integer('included_balance')->default(0); // part of the balance that expires at period end
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id'], 'credit_accounts_owner_unique');
        });

        $cell->create('credit_ledger', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('account_id')->constrained('credit_accounts');
            // grant_included, purchase, allocate_out, allocate_in, consume, refund, expire, adjust
            $table->string('entry_type', 16);
            $table->integer('amount'); // signed credits
            $table->integer('included_amount')->default(0); // the part that came from (or returns to) included credits
            $table->string('period', 7)->nullable(); // YYYY-MM the entry counts towards
            $table->string('action_key', 80)->nullable();
            $table->unsignedBigInteger('user_id')->nullable(); // who used or moved the credits
            $table->boolean('automation')->default(false);
            $table->string('idempotency_key', 191)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at', 6);
            $table->unique(['tenant_id', 'idempotency_key'], 'credit_ledger_idem_unique');
            $table->index(['tenant_id', 'period', 'entry_type'], 'credit_ledger_period_idx');
            $table->index(['account_id', 'id']);
        });

        $cell->create('credit_policies', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->boolean('agents_use_pool')->default(true);
            $table->unsignedInteger('agent_monthly_cap')->nullable(); // most an agent may use from the pool per month
            $table->json('alerts_sent')->nullable(); // {"2026-06": [80, 100]}
            $table->timestamps();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('credit_policies');
        $cell->dropIfExists('credit_ledger');
        $cell->dropIfExists('credit_accounts');
        $control = Schema::connection(Connections::control());
        $control->table('billing_packages', fn (Blueprint $table) => $table->dropColumn('included_credits'));
        $control->dropIfExists('credit_rate_card');
        $control->dropIfExists('credit_settings');
    }
};
