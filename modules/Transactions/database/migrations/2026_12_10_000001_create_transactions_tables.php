<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Transactions core (specs/transactions/01, Sprint 11B-T): deals, parties, key dates, checklist
 * templates, checklist items, documents with versions, item events and the fix-reason library.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('tx_deals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('type', 20);   // listing, buyer, dual, lease, referral, new_construction
            $table->string('status', 16); // pre_contract, under_contract, closed, fell_through
            $table->unsignedBigInteger('agent_user_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('street', 160);
            $table->string('city', 80)->nullable();
            $table->char('state_code', 2);
            $table->string('postal_code', 10)->nullable();
            $table->string('mls_number', 30)->nullable();
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->unsignedBigInteger('gci_cents')->default(0);
            $table->date('contract_date')->nullable();
            $table->date('closing_date')->nullable();
            $table->date('closed_on')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->unsignedBigInteger('calculation_id')->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status'], 'tx_deals_status_idx');
            $table->index(['tenant_id', 'agent_user_id'], 'tx_deals_agent_idx');
        });

        $schema->create('tx_parties', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->string('role', 20); // buyer, seller, co_op_agent, lender, title, escrow, attorney, inspector, other
            $table->string('name', 160);
            $table->string('company', 160)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 30)->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->timestamps();
        });

        $schema->create('tx_dates', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->string('label', 80);
            $table->date('due_on');
            $table->dateTime('done_at')->nullable();
            $table->timestamps();
        });

        $schema->create('tx_templates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 120);
            $table->string('deal_type', 20);
            $table->char('state_code', 2)->nullable(); // null: any state
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $schema->create('tx_template_items', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('template_id')->constrained('tx_templates')->cascadeOnDelete();
            $table->string('label', 160);
            $table->boolean('required')->default(true);
            $table->string('stage', 12)->default('contract'); // listing, contract, closing
            $table->unsignedSmallInteger('sort')->default(0);
        });

        $schema->create('tx_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->string('label', 160);
            $table->boolean('required')->default(true);
            $table->string('stage', 12);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('status', 12); // missing, submitted, needs_fix, approved, waived
            $table->dateTime('submitted_at')->nullable();
            $table->string('fix_reason', 300)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
        });

        $schema->create('tx_documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('filename', 200);
            $table->string('path', 300);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('via', 10); // upload, photo, email
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });

        $schema->create('tx_fix_reasons', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('text', 300);
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();
        });

        $schema->create('tx_settings', function (Blueprint $table): void {
            $table->tenant();
            $table->unsignedSmallInteger('review_sla_hours')->default(24);
            $table->timestamps();
            $table->primary('tenant_id');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        foreach (['tx_settings', 'tx_fix_reasons', 'tx_documents', 'tx_items', 'tx_template_items', 'tx_templates', 'tx_dates', 'tx_parties', 'tx_deals'] as $t) {
            $schema->dropIfExists($t);
        }
    }
};
