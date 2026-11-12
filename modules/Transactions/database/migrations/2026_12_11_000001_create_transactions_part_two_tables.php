<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Transactions core part 2 (specs/transactions/01): earnest money, title companies and safe CDAs,
 * referrals in and out, reviewer assignment, brokerage wire details with two-person verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('tx_deposits', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('held_by', 12);      // trust (brokerage trust account), title, other
            $table->string('held_by_name', 160)->nullable();
            $table->date('due_on')->nullable();
            $table->string('status', 10);       // expected, received, released, refunded
            $table->date('received_on')->nullable();
            $table->string('method', 20)->nullable();
            $table->string('reference', 60)->nullable();
            $table->date('released_on')->nullable();
            $table->string('released_to', 160)->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('release_journal_id')->nullable();
            $table->timestamps();
        });

        $schema->create('tx_title_companies', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 160);
            $table->string('email', 254);
            $table->string('phone', 30);
            $table->dateTime('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->string('verification_note', 300)->nullable();
            $table->timestamps();
        });

        $schema->create('tx_cdas', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('payload');
            $table->string('status', 12); // draft, approved, sent, acknowledged, superseded
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('title_company_id')->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('viewed_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->string('acknowledged_by', 120)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['deal_id', 'version'], 'tx_cdas_version_unq');
        });

        $schema->create('tx_referrals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->string('direction', 3); // in, out
            $table->string('brokerage', 160);
            $table->string('agent_name', 160);
            $table->string('agent_email', 254)->nullable();
            $table->decimal('percent', 5, 2);
            $table->unsignedBigInteger('agreement_document_id')->nullable();
            $table->boolean('w9_received')->default(false);
            $table->timestamps();
        });

        $schema->table('tx_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('assigned_reviewer_id')->nullable();
        });

        $schema->table('tx_settings', function (Blueprint $table): void {
            $table->string('wire_bank_name', 120)->nullable();
            $table->char('wire_last4', 4)->nullable();
            $table->dateTime('wire_verified_at')->nullable();
            $table->unsignedBigInteger('wire_verified_by')->nullable();
            $table->json('wire_pending')->nullable(); // proposed change awaiting a second person's call-back
            $table->unsignedTinyInteger('retention_years')->default(5);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_settings', fn (Blueprint $t) => $t->dropColumn(['wire_bank_name', 'wire_last4', 'wire_verified_at', 'wire_verified_by', 'wire_pending', 'retention_years']));
        $schema->table('tx_items', fn (Blueprint $t) => $t->dropColumn('assigned_reviewer_id'));
        foreach (['tx_referrals', 'tx_cdas', 'tx_title_companies', 'tx_deposits'] as $t) {
            $schema->dropIfExists($t);
        }
    }
};
