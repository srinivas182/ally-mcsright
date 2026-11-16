<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Deal-IQ DQ5a (specs/ai/02, §§19–20): deal-type playbooks (checklist, dates and emails per kind of deal, editable
 * by the brokerage), the MLS status guard, linked 1031 exchange deals, and calendar feeds for the people on a deal.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_deals', function (Blueprint $table): void {
            $table->string('playbook', 20)->nullable();
            $table->dateTime('status_changed_at')->nullable();
            $table->json('terms')->nullable(); // from the accepted offer: option days, earnest money
            $table->unsignedBigInteger('linked_deal_id')->nullable(); // 1031: the sale and the replacement purchase
        });
        $schema->create('tx_playbooks', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('key', 20);
            $table->json('definition');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });
        $schema->create('tx_playbook_steps', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->string('step', 40);
            $table->dateTime('due_at');
            $table->string('status', 8); // waiting, ready, sent, skipped
            $table->dateTime('sent_at')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->timestamps();
            $table->unique(['deal_id', 'step']);
        });
        $schema->create('tx_mls_checks', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->string('expected', 30);
            $table->string('seen', 30)->nullable();
            $table->date('due_on');
            $table->dateTime('reminded_at')->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['deal_id', 'expected']);
        });
        $schema->table('tx_deadline_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('mls_pending_days')->default(2); // business days to show a contract on the MLS
            $table->unsignedTinyInteger('mls_closed_days')->default(2);
            $table->unsignedTinyInteger('mls_active_days')->default(2); // back on the market after it fell through
        });
        $schema->table('tx_portal_access', function (Blueprint $table): void {
            $table->string('calendar_hash', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_portal_access', function (Blueprint $table): void {
            $table->dropUnique(['calendar_hash']);
            $table->dropColumn('calendar_hash');
        });
        $schema->table('tx_deadline_settings', fn (Blueprint $table) => $table->dropColumn(['mls_pending_days', 'mls_closed_days', 'mls_active_days']));
        $schema->dropIfExists('tx_mls_checks');
        $schema->dropIfExists('tx_playbook_steps');
        $schema->dropIfExists('tx_playbooks');
        $schema->table('tx_deals', fn (Blueprint $table) => $table->dropColumn(['playbook', 'status_changed_at', 'terms', 'linked_deal_id']));
    }
};
