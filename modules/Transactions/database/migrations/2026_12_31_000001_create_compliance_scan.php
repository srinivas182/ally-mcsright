<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Deal-IQ compliance scan (specs/ai/02, Sprint 15B): findings on each deal (blocker, warning, info) with evidence
 * and a fix, the brokerage's check settings and plain-English rules, broker approvals required by rules, and
 * each deal's broker-ready score and file submission.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('tx_findings', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('severity', 8); // blocker, warning, info
            $table->char('fingerprint', 40); // the same problem keeps the same finding across scans
            $table->string('message', 400);
            $table->json('evidence')->nullable(); // {document, page, quote}
            $table->json('fix')->nullable(); // {action, label, ...}
            $table->string('status', 10)->default('open'); // open, resolved, dismissed
            $table->unsignedBigInteger('dismissed_by')->nullable();
            $table->string('dismiss_reason', 300)->nullable();
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('resolved_at')->nullable();
            $table->unique(['deal_id', 'fingerprint']);
            $table->index(['tenant_id', 'code', 'status']);
        });

        $schema->create('tx_compliance_checks', function (Blueprint $table): void {
            $table->tenant();
            $table->string('code', 40);
            $table->boolean('enabled')->default(true);
            $table->string('severity', 8)->nullable(); // override of the default
            $table->primary(['tenant_id', 'code']);
        });

        $schema->create('tx_compliance_rules', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('text', 500); // as the broker wrote it
            $table->json('rule'); // structured: applies_to, requires, severity
            $table->string('summary', 300); // how RightAlly understood it, confirmed by the broker
            $table->boolean('active')->default(false);
            $table->unsignedBigInteger('created_by');
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });

        $schema->create('tx_rule_approvals', function (Blueprint $table): void {
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->unsignedBigInteger('rule_id');
            $table->unsignedBigInteger('approved_by');
            $table->dateTime('approved_at');
            $table->primary(['deal_id', 'rule_id']);
        });

        $schema->table('tx_deals', function (Blueprint $table): void {
            $table->unsignedTinyInteger('compliance_score')->nullable();
            $table->dateTime('compliance_scanned_at')->nullable();
            $table->dateTime('file_submitted_at')->nullable();
            $table->unsignedBigInteger('file_submitted_by')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_deals', fn (Blueprint $t) => $t->dropColumn(['compliance_score', 'compliance_scanned_at', 'file_submitted_at', 'file_submitted_by']));
        $schema->dropIfExists('tx_rule_approvals');
        $schema->dropIfExists('tx_compliance_rules');
        $schema->dropIfExists('tx_compliance_checks');
        $schema->dropIfExists('tx_findings');
    }
};
