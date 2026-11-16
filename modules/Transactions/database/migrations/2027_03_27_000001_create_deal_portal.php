<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Deal-IQ DQ3 (specs/ai/02, §§6, 22): the deal portal for clients and the other side (sign-in links, no password),
 * the client concierge's questions, the updates sent (each once), the post-closing check-in, and documents the
 * agent shares with clients.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->create('tx_portal_access', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->string('role', 20); // buyer, seller, lender, title, escrow, attorney, inspector, co_op_agent, other
            $table->string('name', 160);
            $table->string('email', 254);
            $table->string('locale', 2)->default('en'); // en, es
            $table->string('phone', 20)->nullable();
            $table->dateTime('sms_consent_at')->nullable(); // texts only with the client's own opt-in, 8am–9pm
            $table->string('login_hash', 64)->nullable()->unique();
            $table->dateTime('login_expires_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->unsignedBigInteger('invited_by');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['deal_id', 'email']);
            $table->index(['tenant_id', 'email']);
        });
        $schema->create('tx_portal_questions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->foreignId('access_id')->constrained('tx_portal_access')->cascadeOnDelete();
            $table->string('question', 1000);
            $table->text('answer')->nullable();
            $table->json('sources')->nullable();
            $table->string('status', 12); // answered, to_agent, replied
            $table->text('agent_reply')->nullable();
            $table->dateTime('replied_at')->nullable();
            $table->timestamps();
        });
        $schema->create('tx_portal_updates', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('access_id')->constrained('tx_portal_access')->cascadeOnDelete();
            $table->string('kind', 12); // milestone, date, weekly, checkin
            $table->string('ref', 40);
            $table->string('channel', 6); // email, sms
            $table->dateTime('sent_at');
            $table->unique(['access_id', 'kind', 'ref', 'channel']);
        });
        $schema->create('tx_portal_feedback', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->foreignId('access_id')->constrained('tx_portal_access')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('comment', 1000)->nullable();
            $table->boolean('may_quote')->default(false);
            $table->timestamps();
            $table->unique('access_id');
        });
        $schema->table('tx_documents', function (Blueprint $table): void {
            $table->dateTime('shared_at')->nullable(); // shared with the deal's clients in the portal
        });
        $schema->table('tx_deals', function (Blueprint $table): void {
            $table->string('stage_shown', 20)->nullable(); // the last milestone clients were told about
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_deals', fn (Blueprint $table) => $table->dropColumn('stage_shown'));
        $schema->table('tx_documents', fn (Blueprint $table) => $table->dropColumn('shared_at'));
        $schema->dropIfExists('tx_portal_feedback');
        $schema->dropIfExists('tx_portal_updates');
        $schema->dropIfExists('tx_portal_questions');
        $schema->dropIfExists('tx_portal_access');
    }
};
