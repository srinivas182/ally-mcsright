<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * IDX6: the IDX agreement per board (HQ's template, who signs, where the signed copy goes) and, per brokerage and board,
 * the signing, the kept copy, the website check before sending, and the nightly compliance check.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::control());
        $schema->table('idx_boards', function (Blueprint $table): void {
            $table->string('agreement_path', 300)->nullable(); // the MLS's own agreement (PDF); a signature page is added to it
            $table->text('agreement_text')->nullable(); // terms printed on the signature page, with {brokerage} {mls_id} {website} …
            $table->boolean('managing_broker_signs')->default(true);
            $table->string('send_to', 500)->nullable(); // where the signed agreement goes (Repliers desk, the MLS)
            $table->string('send_cc', 500)->nullable();
            $table->string('send_bcc', 500)->nullable();
        });
        $schema->table('idx_subscriptions', function (Blueprint $table): void {
            $table->string('packet_ulid', 26)->nullable()->index();
            $table->string('signed_path', 300)->nullable(); // RightAlly's kept copy of the sealed agreement
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('sent_to_mls_at')->nullable();
            $table->string('website', 190)->nullable();
            $table->boolean('compliance_ok')->nullable();
            $table->string('compliance_note', 300)->nullable();
            $table->timestamp('compliance_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::control());
        $schema->table('idx_subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['packet_ulid']);
            $table->dropColumn(['packet_ulid', 'signed_path', 'signed_at', 'sent_to_mls_at', 'website', 'compliance_ok', 'compliance_note', 'compliance_checked_at']);
        });
        $schema->table('idx_boards', function (Blueprint $table): void {
            $table->dropColumn(['agreement_path', 'agreement_text', 'managing_broker_signs', 'send_to', 'send_cc', 'send_bcc']);
        });
    }
};
