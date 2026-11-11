<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Database intelligence part 1 (CRM additions 10-12, 15): intents read from notes, opportunity
 * scores with reasons. Intent summaries are encrypted (they quote the client).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('crm_intents', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->string('kind', 20); // buy, sell, relocate, lease_end, retire, family_change, other
            $table->text('summary');
            $table->date('due_on')->nullable();
            $table->string('confidence', 10)->default('medium');
            $table->string('status', 10)->default('open'); // open, done, dismissed
            $table->unsignedBigInteger('source_activity_id')->nullable();
            $table->unsignedBigInteger('alert_task_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'contact_id', 'status'], 'crm_intents_contact_idx');
            $table->index(['tenant_id', 'status', 'due_on'], 'crm_intents_due_idx');
        });

        $schema->create('crm_contact_scores', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('contact_id')->unique()->constrained('crm_contacts')->cascadeOnDelete();
            $table->unsignedTinyInteger('buyer');
            $table->unsignedTinyInteger('seller');
            $table->string('timeframe', 10); // now, 3m, 6m, 12m, later, unknown
            $table->json('reasons');
            $table->dateTime('scored_at');

            $table->index(['tenant_id', 'buyer'], 'crm_scores_buyer_idx');
            $table->index(['tenant_id', 'seller'], 'crm_scores_seller_idx');
        });

        $schema->table('crm_contacts', function (Blueprint $table): void {
            $table->dateTime('analysed_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('crm_contacts', fn (Blueprint $table) => $table->dropColumn('analysed_at'));
        $schema->dropIfExists('crm_contact_scores');
        $schema->dropIfExists('crm_intents');
    }
};
