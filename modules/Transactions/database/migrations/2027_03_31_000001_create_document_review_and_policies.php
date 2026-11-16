<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Deal-IQ DQ6 (specs/ai/02 and specs/ai/05): AI document review (addenda a contract marks as attached, file approval with
 * a review brief) and Compliance Knowledge (the brokerage's policy manual, agents' questions answered from it, the
 * gaps, and policy changes agents acknowledge).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_document_readings', function (Blueprint $table): void {
            $table->json('referenced')->nullable(); // addenda the contract marks as attached: [{name, page, quote}]
        });
        $schema->table('tx_deals', function (Blueprint $table): void {
            $table->dateTime('file_approved_at')->nullable();
            $table->unsignedBigInteger('file_approved_by')->nullable();
            $table->dateTime('file_returned_at')->nullable();
            $table->string('file_return_note', 2000)->nullable();
        });
        $schema->create('tx_review_briefs', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->text('summary');
            $table->text('note'); // a suggested "send back" note for the agent
            $table->boolean('ai')->default(false);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        $schema->create('tx_policy_docs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('title', 160);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 10); // active, superseded, removed
            $table->unsignedBigInteger('replaces_id')->nullable();
            $table->text('change_summary')->nullable();
            $table->unsignedSmallInteger('pages')->default(0);
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
        });
        $schema->create('tx_policy_chunks', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('doc_id')->constrained('tx_policy_docs')->cascadeOnDelete();
            $table->unsignedSmallInteger('page');
            $table->text('text');
        });
        $schema->create('tx_policy_questions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('question', 1000);
            $table->text('answer')->nullable();
            $table->json('sources')->nullable();
            $table->string('status', 10); // answered, to_broker, replied
            $table->text('broker_reply')->nullable();
            $table->unsignedBigInteger('replied_by')->nullable();
            $table->dateTime('replied_at')->nullable();
            $table->dateTime('gap_closed_at')->nullable(); // the broker added it to the manual
            $table->timestamps();
        });
        $schema->create('tx_policy_acks', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('doc_id')->constrained('tx_policy_docs')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->dateTime('acknowledged_at');
            $table->unique(['doc_id', 'user_id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        foreach (['tx_policy_acks', 'tx_policy_questions', 'tx_policy_chunks', 'tx_policy_docs', 'tx_review_briefs'] as $t) {
            $schema->dropIfExists($t);
        }
        $schema->table('tx_deals', fn (Blueprint $table) => $table->dropColumn(['file_approved_at', 'file_approved_by', 'file_returned_at', 'file_return_note']));
        $schema->table('tx_document_readings', fn (Blueprint $table) => $table->dropColumn('referenced'));
    }
};
