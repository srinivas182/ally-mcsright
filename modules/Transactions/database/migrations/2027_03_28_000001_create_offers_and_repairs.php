<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Deal-IQ DQ4 (specs/ai/02, §§16–17): offers on a listing (an offer link for buyers' agents, a net-to-seller sheet,
 * rounds and notices sent to every buyer's agent at the same moment, a summary for the seller) and repair requests
 * read from the inspection report.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_deals', function (Blueprint $table): void {
            $table->json('net_sheet')->nullable(); // the seller's costs for net-to-seller: commission %, title %, payoff, other
        });
        $schema->create('tx_offer_links', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->unsignedBigInteger('created_by');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });
        $schema->create('tx_offers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->string('label', 2); // A, B, C … (what the seller sees: no buyer names)
            $table->string('source', 8); // link, upload, manual
            $table->string('status', 12); // received, shortlisted, countered, accepted, declined, withdrawn
            $table->string('agent_name', 160)->nullable();
            $table->string('agent_email', 254)->nullable();
            $table->string('agent_phone', 20)->nullable();
            $table->string('brokerage', 160)->nullable();
            $table->string('buyer_names', 300)->nullable();
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->string('financing', 12)->nullable();
            $table->decimal('down_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('earnest_cents')->nullable();
            $table->unsignedBigInteger('option_fee_cents')->nullable();
            $table->unsignedSmallInteger('option_days')->nullable();
            $table->unsignedBigInteger('concessions_cents')->default(0);
            $table->unsignedBigInteger('buyer_agent_cents')->default(0); // buyer's agent pay asked of the seller
            $table->date('closing_date')->nullable();
            $table->json('contingencies')->nullable(); // inspection, financing, appraisal, sale_of_home
            $table->unsignedBigInteger('appraisal_gap_cents')->nullable();
            $table->unsignedBigInteger('escalation_cap_cents')->nullable();
            $table->unsignedBigInteger('escalation_step_cents')->nullable();
            $table->string('proof', 14)->nullable(); // none, prequal, preapproval, proof_of_funds
            $table->unsignedSmallInteger('leaseback_days')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('file_path')->nullable(); // kept apart from the deal's documents: an offer is not the contract
            $table->string('file_name', 200)->nullable();
            $table->json('read')->nullable(); // what Deal-IQ read from the uploaded offer, with page and quote
            $table->string('update_hash', 64)->nullable()->unique(); // the buyer's agent's link to revise their offer
            $table->unsignedSmallInteger('round')->default(1);
            $table->json('history')->nullable(); // earlier versions of the terms
            $table->string('packet', 26)->nullable(); // counter-offer draft (forms packet)
            $table->dateTime('submitted_at');
            $table->timestamps();
        });
        $schema->create('tx_offer_notices', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->string('kind', 14); // multiple, highest_best, decision
            $table->text('message');
            $table->dateTime('deadline_at')->nullable();
            $table->json('recipients'); // [{offer, email}]: everyone got the same words at the same moment
            $table->unsignedBigInteger('sent_by');
            $table->dateTime('sent_at');
        });
        $schema->create('tx_offer_summaries', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->text('text_en');
            $table->text('text_es')->nullable();
            $table->json('offers'); // the comparison the words were written from
            $table->boolean('ai')->default(false);
            $table->unsignedBigInteger('created_by');
            $table->dateTime('shared_at')->nullable(); // shown to the sellers in the deal portal
            $table->timestamps();
        });
        $schema->create('tx_repair_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->unsignedBigInteger('document_id');
            $table->string('status', 10); // reading, draft, drafted, failed
            $table->json('items')->nullable();
            $table->string('packet', 26)->nullable();
            $table->dateTime('shared_at')->nullable(); // shown to the buyers in the deal portal
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        foreach (['tx_repair_requests', 'tx_offer_summaries', 'tx_offer_notices', 'tx_offers', 'tx_offer_links'] as $t) {
            $schema->dropIfExists($t);
        }
        $schema->table('tx_deals', fn (Blueprint $table) => $table->dropColumn('net_sheet'));
    }
};
